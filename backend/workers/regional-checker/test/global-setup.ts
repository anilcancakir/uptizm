/**
 * A real TCP listener for the `probeTcp` path, started once before the suite.
 *
 * There is no protocol-level mock for `cloudflare:sockets` `connect()`, and that
 * is a documented negative rather than a gap nobody got round to filling. The
 * suite runs in real `workerd`, so the probe opens a genuine outbound socket, and
 * upstream's own hyperdrive fixture answers this the same way: a
 * `net.createServer` on a kernel-assigned port, handed to the tests, with the
 * probe pointed at `127.0.0.1:<port>`. The TCP path is therefore exercised for
 * real or not at all; there is no third option to reach for.
 *
 * Port 0 rather than a literal, because a fixed port collides with whatever else
 * is on the machine and CI runs the three halves of this repository in parallel.
 *
 * This file runs in NODE, not in workerd: `globalSetup` is executed by the Vitest
 * runner process, which is the only context in this repository that can listen on
 * a socket at all.
 */

import type { TestProject } from "vitest/node";

declare module "vitest" {
    interface ProvidedContext {
        /** The port of the listener below, for the TCP probe cases to aim at. */
        tcpListenerPort: number;
    }
}

/**
 * The slice of `node:net` this fixture uses, declared here.
 *
 * `test/tsconfig.json` is a WORKERS program: its `types` are
 * `@cloudflare/workers-types` plus the pool's, `@types/node` is not installed,
 * and this step may not add a dependency, so a static `import { createServer }
 * from "node:net"` is TS2307 in it (measured, not assumed). Adding Node's types
 * to that program would be the worse trade, because every test file shares it and
 * `process` or `Buffer` would then typecheck in files that run inside workerd
 * where neither exists.
 *
 * So the module is loaded through a variable specifier, which the checker does not
 * resolve, and its shape is DECLARED here instead of asserted away: every call
 * below is checked against these three types, and a wrong argument is still a
 * compile error. What is untyped is one string.
 */
type TcpConnection = {
    end(): void;
};

type TcpServer = {
    listen(port: number, host: string, listening: () => void): void;
    once(event: "error", listener: (error: Error) => void): void;
    address(): { port: number } | string | null;
    close(closed: () => void): void;
};

type NetModule = {
    createServer(connection: (connection: TcpConnection) => void): TcpServer;
};

async function loadNet(): Promise<NetModule> {
    const specifier = "node:net";

    return (await import(specifier)) as NetModule;
}

export default async function setup(project: TestProject): Promise<() => Promise<void>> {
    const { createServer } = await loadNet();

    const server = createServer((connection) => {
        // Reaching `opened` IS the health signal a TCP monitor measures: the probe
        // sends nothing and reads nothing, so accepting the connection and closing
        // it is the whole of what this listener owes it. Closing here also keeps
        // teardown from waiting on a connection that never ends.
        connection.end();
    });

    await new Promise<void>((resolve, reject) => {
        server.once("error", reject);
        server.listen(0, "127.0.0.1", resolve);
    });

    const address = server.address();
    if (address === null || typeof address === "string") {
        throw new Error("the TCP fixture listener reported no port to probe");
    }

    project.provide("tcpListenerPort", address.port);

    return async () => {
        await new Promise<void>((resolve) => server.close(resolve));
    };
}
