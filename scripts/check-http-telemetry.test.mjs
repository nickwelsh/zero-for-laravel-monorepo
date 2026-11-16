import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createRequire } from 'node:module';
import test from 'node:test';

// Resolve the instrumentation used by Zero, not a separate test dependency.
const require = createRequire(import.meta.url);
const zeroRequire = createRequire(require.resolve('@rocicorp/zero'));
const autoRequire = createRequire(zeroRequire.resolve('@opentelemetry/auto-instrumentations-node'));
const { HttpInstrumentation } = autoRequire('@opentelemetry/instrumentation-http');
const { NodeSDK } = autoRequire('@opentelemetry/sdk-node');
const sdkRequire = createRequire(autoRequire.resolve('@opentelemetry/sdk-node'));
const { InMemorySpanExporter, SimpleSpanProcessor, AlwaysOnSampler } = sdkRequire('@opentelemetry/sdk-trace-base');

await test('Zero HTTP server telemetry redacts signed query credentials', async (t) => {
    // Stable HTTP semantic conventions exercise the affected url.query path.
    const previous = process.env.OTEL_SEMCONV_STABILITY_OPT_IN;
    process.env.OTEL_SEMCONV_STABILITY_OPT_IN = 'http';
    const exporter = new InMemorySpanExporter();
    const instrumentation = new HttpInstrumentation();
    const sdk = new NodeSDK({
        autoDetectResources: false,
        sampler: new AlwaysOnSampler(),
        logRecordProcessors: [],
        spanProcessors: [new SimpleSpanProcessor(exporter)],
        instrumentations: [instrumentation],
    });
    sdk.start();
    const http = require('http');
    const server = http.createServer((_req, res) => res.end('ok'));
    t.after(async () => {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        instrumentation.disable();
        await sdk.shutdown();
        if (previous === undefined) delete process.env.OTEL_SEMCONV_STABILITY_OPT_IN;
        else process.env.OTEL_SEMCONV_STABILITY_OPT_IN = previous;
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    await new Promise((resolve, reject) => {
        const request = http.get(
            {
                hostname: '127.0.0.1',
                port: server.address().port,
                path: '/download?sig=synthetic-secret&X-Goog-Signature=synthetic-google&view=summary',
                agent: false,
            },
            (response) => {
                response.resume();
                response.on('end', resolve);
            },
        );
        request.on('error', reject);
        request.setTimeout(3000, () => request.destroy(new Error('Local telemetry test timed out')));
    });
    await new Promise((resolve) => setImmediate(resolve));
    const serverSpan = exporter.getFinishedSpans().find((span) => span.kind === 1);
    assert.ok(serverSpan, 'expected an incoming server span');
    const query = serverSpan.attributes['url.query'];
    assert.equal(typeof query, 'string');
    assert.ok(query.includes('view=summary'), 'non-sensitive context should be retained');
    assert.ok(!query.includes('synthetic-secret'), 'SAS signature must be redacted');
    assert.ok(!query.includes('synthetic-google'), 'GCS signature must be redacted');
    assert.ok(query.includes('REDACTED'));
});
