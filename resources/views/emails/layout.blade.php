<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f7; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 32px 24px; text-align: center; border-radius: 12px 12px 0 0; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; }
        .header p { color: rgba(255,255,255,0.85); margin: 8px 0 0; font-size: 14px; }
        .body { background: #ffffff; padding: 32px 24px; border-radius: 0 0 12px 12px; }
        .body h2 { color: #1a1a2e; font-size: 20px; margin: 0 0 16px; }
        .body p { color: #4a4a6a; line-height: 1.6; margin: 0 0 12px; font-size: 15px; }
        .details { background: #f8f9ff; border-radius: 8px; padding: 16px; margin: 16px 0; }
        .details table { width: 100%; border-collapse: collapse; }
        .details td { padding: 8px 0; font-size: 14px; }
        .details td:first-child { color: #8888aa; width: 40%; }
        .details td:last-child { color: #1a1a2e; font-weight: 500; }
        .cta-button { display: inline-block; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; font-size: 15px; margin: 16px 0; }
        .footer { text-align: center; padding: 24px; color: #8888aa; font-size: 12px; }
        .footer a { color: #6366f1; text-decoration: none; }
        .divider { border: none; border-top: 1px solid #e8e8f0; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>@yield('title', 'UgoCosmo')</h1>
            <p>@yield('subtitle', '')</p>
        </div>
        <div class="body">
            @yield('content')
            <hr class="divider">
            <p style="font-size: 13px; color: #8888aa; text-align: center;">
                This email was sent by UgoCosmo. If you have any questions, please contact our support team.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} UgoCosmo. All rights reserved.</p>
            <p><a href="{{ config('app.url') }}">{{ config('app.name') }}</a></p>
        </div>
    </div>
</body>
</html>
