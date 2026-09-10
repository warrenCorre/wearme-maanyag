<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Wear Me Maanyag</title>
    @vite('resources/css/app.css')
</head>

<body>
    <div>
        <h1>Wear Me Maanyag</h1>
        <h2>Forgot Password</h2>

        <p>Enter your email address and we will send a password reset link if an account exists.</p>

        @if (session('status'))
            <div role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
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

            <button type="submit">Send Password Reset Link</button>
        </form>

        <p>
            <a href="{{ route('login') }}">Back to Login</a>
        </p>
    </div>
</body>
</html>
