<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
               line-height: 1.6; color: #3C315B; background-color: #F6F2FF; margin: 0; padding: 0; }
        .container { max-width: 480px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 24px; padding: 32px;
                box-shadow: 0 4px 16px rgba(60, 49, 91, 0.08); }
        h1 { font-size: 24px; font-weight: 700; margin: 0 0 16px 0; text-align: center; }
        h2 { font-size: 16px; font-weight: 700; margin: 24px 0 8px 0; }
        p { margin: 0 0 12px 0; }
        .code { font-size: 32px; font-weight: 700; letter-spacing: 6px; text-align: center;
                background: #F6F2FF; border-radius: 16px; padding: 20px; margin: 20px 0; }
        .child { background: #F6F2FF; border-radius: 16px; padding: 16px; margin: 12px 0; }
        .child .name { font-weight: 700; }
        .child .child-code { font-size: 24px; font-weight: 700; letter-spacing: 4px; }
        .cta { display: block; text-align: center; background: #6B4EFF; color: #ffffff !important;
               text-decoration: none; border-radius: 16px; padding: 16px; font-weight: 700; margin: 20px 0; }
        .muted { color: #7A6F99; font-size: 13px; }
        .footer { text-align: center; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>{{ $title }}</h1>
            <p>{{ __('consent.greeting') }}</p>
            @yield('body')
        </div>
        <p class="muted footer">{{ __('consent.footer') }}</p>
    </div>
</body>
</html>
