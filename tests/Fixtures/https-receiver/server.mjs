import { createServer } from "node:https";
import { readFileSync } from "node:fs";
import { createHmac, timingSafeEqual } from "node:crypto";

let signingSecret = null;
let failuresRemaining = 0;
let verifiedAttempts = 0;
const received = new Map();

const MAX_BODY_BYTES = 1024 * 1024;
const MAX_AGE_SECONDS = 300;

async function readBody(request) {
    const chunks = [];
    let length = 0;

    for await (const chunk of request) {
        length += chunk.length;

        if (length > MAX_BODY_BYTES) {
            throw new Error("Request body too large");
        }

        chunks.push(chunk);
    }

    return Buffer.concat(chunks);
}

function respond(response, status, body = "") {
    response.writeHead(status, {
        "content-type": "text/plain",
    });

    response.end(body);
}

const server = createServer(
    {
        key: readFileSync("/tls/server.key"),
        cert: readFileSync("/tls/server.crt"),
    },
    async (request, response) => {
        try {
            if (request.method === "GET" && request.url === "/health") {
                respond(response, 200, "ok");
                return;
            }

            if (
                request.method === "POST" &&
                request.url === "/__test/configure"
            ) {
                const config = JSON.parse(
                    (await readBody(request)).toString("utf8"),
                );

                if (
                    typeof config.secret !== "string" ||
                    !/^[a-f0-9]{64}$/.test(config.secret)
                ) {
                    respond(response, 400, "Invalid test secret");
                    return;
                }

                const failures = config.failuresBeforeSuccess ?? 0;

                if (
                    !Number.isInteger(failures) ||
                    failures < 0 ||
                    failures > 3
                ) {
                    respond(response, 400, "Invalid failure count");
                    return;
                }

                signingSecret = config.secret;
                failuresRemaining = failures;
                verifiedAttempts = 0;
                received.clear();

                respond(response, 204);
                return;
            }

            if (
                request.method === "GET" &&
                request.url === "/__test/received"
            ) {
                response.writeHead(200, {
                    "content-type": "application/json",
                });

                response.end(
                    JSON.stringify({
                        count: received.size,
                        verifiedAttempts,
                        failuresRemaining,
                        deliveries: Array.from(received, ([id, payload]) => ({
                            id,
                            payload,
                        })),
                    }),
                );

                return;
            }

            if (request.method !== "POST" || request.url !== "/hooks") {
                respond(response, 404, "Not found");
                return;
            }

            if (signingSecret === null) {
                respond(response, 503, "Receiver not configured");
                return;
            }

            const body = await readBody(request);

            const deliveryId = request.headers["x-eventflow-delivery-id"];

            const timestampHeader = request.headers["x-eventflow-timestamp"];

            const signature = request.headers["x-eventflow-signature"];

            const contentType = (request.headers["content-type"] ?? "")
                .split(";")[0]
                .trim();

            if (
                typeof deliveryId !== "string" ||
                deliveryId === "" ||
                typeof timestampHeader !== "string" ||
                !/^[1-9][0-9]*$/.test(timestampHeader) ||
                typeof signature !== "string" ||
                !/^v1=[a-f0-9]{64}$/.test(signature) ||
                contentType !== "application/json"
            ) {
                respond(response, 400, "Invalid webhook headers");
                return;
            }

            const timestamp = Number(timestampHeader);

            if (
                !Number.isSafeInteger(timestamp) ||
                Math.abs(Date.now() / 1000 - timestamp) > MAX_AGE_SECONDS
            ) {
                respond(response, 401, "Invalid timestamp");
                return;
            }

            const expected = createHmac("sha256", signingSecret)
                .update(`v1.${timestampHeader}.${deliveryId}.`)
                .update(body)
                .digest();

            const supplied = Buffer.from(signature.slice(3), "hex");

            if (
                supplied.length !== expected.length ||
                !timingSafeEqual(expected, supplied)
            ) {
                respond(response, 401, "Invalid signature");
                return;
            }

            if (received.has(deliveryId)) {
                respond(response, 204);
                return;
            }

            verifiedAttempts += 1;

            if (failuresRemaining > 0) {
                failuresRemaining -= 1;

                console.log(`Simulated HTTP 503: ${deliveryId}`);

                respond(response, 503, "Temporary failure");
                return;
            }

            const payload = JSON.parse(body.toString("utf8"));

            received.set(deliveryId, payload);

            console.log(`Webhook verified: ${deliveryId}`);

            respond(response, 204);
        } catch (error) {
            console.error("Webhook request error:", error.message);
            respond(response, 400, "Invalid request");
        }
    },
);

server.listen(8443, "0.0.0.0", () => {
    console.log("HTTPS webhook receiver listening on 8443");
});
