<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ログイン | MUSAKOドラム教室</title></head>
<body><main><h1>MUSAKOドラム教室 ログイン</h1>
<form method="post" action="/login">@csrf
<label>メールアドレス <input type="email" name="email" value="{{ old('email') }}" required></label>
@error('email') <p>{{ $message }}</p> @enderror
<label>パスワード <input type="password" name="password" required></label>
<label><input type="checkbox" name="remember" value="1"> ログイン状態を保持</label>
<button type="submit">ログイン</button></form></main></body></html>
