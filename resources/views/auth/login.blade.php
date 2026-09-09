<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Wear Me Maanyag</title>
    @vite('resources/css/app.css')
</head>

<body>
    <div>
        <h1>Wear Me Maanyag</h1>
        <h2>Login</h2>

        <form method="POST" action="{{ route('login.authenticate') }}">
            @csrf

            <div>
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                >
            </div>

            <div>
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >
            </div>

            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>