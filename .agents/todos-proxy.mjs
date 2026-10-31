import http from 'node:http';
import net from 'node:net';

// Laravel and Zero must share one authenticated portal origin, including WebSockets.
const server = http.createServer((request, response) => {
    const port = request.url.startsWith('/sync/') ? 4858 : 8090;
    const upstream = http.request(
        { host: '127.0.0.1', port, path: request.url, method: request.method, headers: request.headers },
        (result) => {
            response.writeHead(result.statusCode, result.headers);
            result.pipe(response);
        },
    );
    upstream.on('error', () => {
        if (!response.headersSent) response.writeHead(502);
        response.end('Demo service unavailable');
    });
    request.pipe(upstream);
});
server.on('upgrade', (request, socket, head) => {
    if (!request.url.startsWith('/sync/')) {
        socket.destroy();
        return;
    }
    const upstream = net.connect(4858, '127.0.0.1', () => {
        let headers = `${request.method} ${request.url} HTTP/${request.httpVersion}\r\n`;
        for (let i = 0; i < request.rawHeaders.length; i += 2) {
            headers += `${request.rawHeaders[i]}: ${request.rawHeaders[i + 1]}\r\n`;
        }
        upstream.write(`${headers}\r\n`);
        if (head.length) upstream.write(head);
        socket.pipe(upstream);
        upstream.pipe(socket);
    });
    upstream.on('error', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
    socket.on('close', () => upstream.destroy());
    upstream.on('close', () => socket.destroy());
});
server.listen(Number(process.env.PORT), '0.0.0.0');
