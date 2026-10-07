<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel SAML SSO PoC</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 960px; margin: 40px auto; padding: 0 20px; color: #243046; }
        a.button { display: inline-block; background: #2456b8; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th,td { padding: 12px; border: 1px solid #ccd5df; text-align: left; overflow-wrap: anywhere; }
        th { background: #eff3f8; } pre { white-space: pre-wrap; margin: 0; }
    </style>
</head>
<body>
    <h1>Laravel SAML SSO PoC</h1>
    <p>Provider: <strong>{{ config('saml.provider') }}</strong></p>
    @guest
        <a class="button" href="/saml/login">SAMLログイン</a>
    @else
        @php($identity = session('saml.identity', []))
        @php($attributes = $identity['attributes'] ?? [])
        <h2>ログインユーザー</h2>
        <table>
            <tr><th>Laravelユーザー</th><td>{{ auth()->user()->name }} ({{ auth()->user()->email }})</td></tr>
            <tr><th>NameID</th><td>{{ $identity['name_id'] ?? '' }}</td></tr>
            @foreach(config('saml.attributes') as $label => $key)
                <tr><th>{{ $label }}</th><td>{{ implode(', ', $attributes[$key] ?? []) }}</td></tr>
            @endforeach
        </table>
        <h2>SAML Attributes</h2>
        <table><thead><tr><th>key</th><th>value</th></tr></thead><tbody>
            @foreach($attributes as $key => $values)
                <tr><td>{{ $key }}</td><td><pre>{{ json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></td></tr>
            @endforeach
        </tbody></table>
        <a class="button" href="/saml/logout">SAMLログアウト</a>
    @endguest
    <p><a href="/saml/metadata">SP Metadata</a></p>
</body>
</html>
