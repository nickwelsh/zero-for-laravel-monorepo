<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Todo notebook</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f6f7f4; color: #24322e; font: 17px/1.6 system-ui, sans-serif; }
    header, main { padding: 22px 36px; }
    header { display: flex; justify-content: space-between; background: #264c43; color: white; }
    header a { color: inherit; }
    main { max-width: 780px; margin: 30px auto; background: white; border: 1px solid #cbd5cd; }
    small { color: #59685f; font-family: monospace; overflow-wrap: anywhere; }
    form { display: grid; gap: 14px; margin-bottom: 20px; }
    input:not([type=checkbox]) { display: block; width: 100%; padding: 9px; font: inherit; }
    button { justify-self: start; font: inherit; padding: 5px 16px; cursor: pointer; }
    output, [role=alert] { display: block; margin: 22px 0; }
    [role=alert] { color: #9b3632; }
</style><script defer src="/app.js"></script></head><body><x-inertia::app /></body></html>
