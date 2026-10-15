<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('mail.verification.title') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #3C315B;
            background-color: #F6F2FF;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .card {
            background: #ffffff;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(60, 49, 91, 0.08);
        }
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo img {
            height: 48px;
            width: auto;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 16px 0;
            text-align: center;
        }
        p {
            margin: 0 0 16px 0;
            color: #3C315B;
            opacity: 0.8;
        }
        .code-container {
            background-color: #3C315B;
            background: linear-gradient(135deg, #7F68C1 0%, #3C315B 100%);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            margin: 24px 0;
        }
        .code {
            font-size: 36px;
            font-weight: 900;
            letter-spacing: 8px;
            color: #ffffff;
            font-family: 'Courier New', monospace;
        }
        .expiry {
            font-size: 14px;
            color: #3C315B;
            opacity: 0.64;
            text-align: center;
            margin-top: 16px;
        }
        .footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid rgba(60, 49, 91, 0.08);
            text-align: center;
            font-size: 12px;
            color: #3C315B;
            opacity: 0.48;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="logo">
                <img src="{{ asset('images/logo.png') }}" alt="EduAI" style="height: 48px; width: auto;">
            </div>

            <h1>{{ __('mail.verification.title') }}</h1>

            <p>{{ __('mail.verification.greeting') }}</p>
            <p>{{ __('mail.verification.intro') }}</p>

            <div class="code-container" style="background-color: #3C315B; background: linear-gradient(135deg, #7F68C1 0%, #3C315B 100%); border-radius: 16px; padding: 24px; text-align: center; margin: 24px 0;">
                <span class="code" style="font-size: 36px; font-weight: 900; letter-spacing: 8px; color: #ffffff; font-family: 'Courier New', monospace;">{{ $code }}</span>
            </div>

            <p class="expiry">{{ __('mail.verification.expiry', ['minutes' => $expiresInMinutes]) }}</p>

            <p>{{ __('mail.verification.ignore') }}</p>

            <div class="footer">
                <p>{{ __('mail.verification.footer_auto') }}</p>
                <p>{{ __('mail.verification.footer_no_reply') }}</p>
            </div>
        </div>
    </div>
</body>
</html>
