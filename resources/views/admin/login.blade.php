<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top left, rgba(16,185,129,.24), transparent 30%),
                radial-gradient(circle at top right, rgba(59,130,246,.18), transparent 26%),
                linear-gradient(160deg, #020617 0%, #0f172a 100%);
            color: #e2e8f0;
            font-family: Inter, system-ui, sans-serif;
        }
        .card {
            width: min(420px, calc(100vw - 32px));
            padding: 28px;
            border: 1px solid rgba(148,163,184,.18);
            border-radius: 24px;
            background: rgba(15,23,42,.78);
            backdrop-filter: blur(18px);
            box-shadow: 0 30px 80px rgba(0,0,0,.35);
        }
        h1 { margin: 0 0 8px; font-size: 30px; }
        p { margin: 0 0 24px; color: #94a3b8; line-height: 1.6; }
        label { display: block; font-size: 14px; margin: 16px 0 8px; color: #cbd5e1; }
        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(148,163,184,.2);
            background: rgba(255,255,255,.04);
            color: #fff;
            outline: none;
        }
        input:focus { border-color: rgba(16,185,129,.6); }
        button {
            margin-top: 24px;
            width: 100%;
            border: 0;
            border-radius: 14px;
            padding: 14px 16px;
            background: linear-gradient(135deg, #34d399, #22c55e);
            color: #04111d;
            font-weight: 700;
            cursor: pointer;
        }
        .error {
            margin-top: 12px;
            color: #fca5a5;
            font-size: 14px;
        }
        .hint {
            margin-top: 18px;
            font-size: 13px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <form class="card" method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <h1>Admin Login</h1>
        <p>Sign in to manage package prices, orders, and player details.</p>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', env('ADMIN_EMAIL', 'admin@dyzzstore.test')) }}" required>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" value="{{ old('password', env('ADMIN_PASSWORD', 'password')) }}" required>

        <button type="submit">Login</button>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <div class="hint">
            Default local account: {{ env('ADMIN_EMAIL', 'admin@dyzzstore.test') }}
        </div>
    </form>
</body>
</html>
