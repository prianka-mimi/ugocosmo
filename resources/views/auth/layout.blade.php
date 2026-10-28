<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Authentication' }}</title>
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #FFFBF8 0%, #FFF8F5 50%, #FFF5F0 100%);
            color: #1b1b18;
            overflow-x: hidden;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        #networkCanvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .floating-shapes {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 1;
        }

        .circle { position: absolute; border-radius: 50%; border: 1px solid rgba(232, 116, 80, 0.1); }

        .circle-1 { width: 24rem; height: 24rem; top: -5rem; left: -5rem; animation: spin-slow 30s linear infinite; }
        .circle-2 { width: 18rem; height: 18rem; top: -2.5rem; left: -2.5rem; animation: spin-slow-reverse 25s linear infinite; }
        .circle-3 { width: 20rem; height: 20rem; bottom: -5rem; right: -5rem; animation: spin-slow 30s linear infinite; }
        .circle-4 { width: 16rem; height: 16rem; bottom: -2.5rem; right: -2.5rem; animation: spin-slow-reverse 25s linear infinite; }

        .blob { position: absolute; border-radius: 50%; filter: blur(60px); }

        .blob-1 { width: 16rem; height: 16rem; background: linear-gradient(135deg, rgba(232, 116, 80, 0.2), transparent); top: 25%; left: 25%; animation: blob 15s ease-in-out infinite; }
        .blob-2 { width: 18rem; height: 18rem; background: linear-gradient(135deg, rgba(244, 162, 97, 0.15), transparent); top: 33%; right: 25%; animation: blob 15s ease-in-out infinite 2s; }
        .blob-3 { width: 14rem; height: 14rem; background: linear-gradient(135deg, rgba(232, 116, 80, 0.15), transparent); bottom: 25%; left: 33%; animation: blob 15s ease-in-out infinite 4s; }

        .auth-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 10;
            padding: 1.5rem;
        }

        .auth-logo { text-align: center; margin-bottom: 1.5rem; }
        .auth-logo img { max-width: 200px; display: inline-block; }

        .auth-card {
            background: rgba(255, 255, 255, 0.78);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            border-radius: 1.25rem;
            padding: 2rem 1.75rem;
            width: 100%;
            max-width: 26rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04), 0 10px 30px rgba(232, 116, 80, 0.1);
        }

        .auth-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .auth-header h2 { font-size: 1.5rem; font-weight: 700; color: #1b1b18; margin: 0; }
        .auth-header .back-btn { color: #706f6c; text-decoration: none; font-size: 0.8125rem; font-weight: 500; }
        .auth-header .back-btn:hover { color: #E87450; }

        .auth-subtitle { text-align: center; font-size: 0.875rem; color: #706f6c; margin-bottom: 1.25rem; }

        .form-group { margin-bottom: 1.05rem; }
        .form-group label { display: block; font-size: 0.8125rem; font-weight: 600; color: #3d3d3a; margin-bottom: 0.35rem; }
        .form-group input { width: 100%; padding: 0.6rem 0.9rem; border: 1px solid rgba(232, 116, 80, 0.28); border-radius: 0.5rem; background: rgba(255, 255, 255, 0.65); color: #1b1b18; font-size: 0.9375rem; font-family: inherit; outline: none; transition: border-color 0.2s ease; }
        .form-group input:focus { border-color: #E87450; box-shadow: 0 0 0 3px rgba(232, 116, 80, 0.12); background: rgba(255, 255, 255, 0.9); }

        .pwd-group { margin-bottom: 1.05rem; position: relative; }
        .pwd-group label { display: block; font-size: 0.8125rem; font-weight: 600; color: #3d3d3a; margin-bottom: 0.35rem; }
        .pwd-group input { width: 100%; padding: 0.6rem 2.6rem 0.6rem 0.9rem; border: 1px solid rgba(232, 116, 80, 0.28); border-radius: 0.5rem; background: rgba(255, 255, 255, 0.65); color: #1b1b18; font-size: 0.9375rem; font-family: inherit; outline: none; transition: border-color 0.2s ease; }
        .pwd-group input:focus { border-color: #E87450; box-shadow: 0 0 0 3px rgba(232, 116, 80, 0.12); background: rgba(255, 255, 255, 0.9); }
        .pwd-group .eye-btn { position: absolute; right: 0.6rem; bottom: 0.6rem; background: none; border: none; cursor: pointer; color: #8b8b87; padding: 0.3rem; font-size: 0.8rem; }

        .checkbox-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; }
        .checkbox-row input[type="checkbox"] { width: 1rem; height: 1rem; accent-color: #E87450; }
        .checkbox-row label { font-weight: 400; color: #706f6c; font-size: 0.875rem; cursor: pointer; }
        .forgot-link { margin-left: auto; color: #E87450; text-decoration: none; font-size: 0.875rem; font-weight: 600; }

        .btn { width: 100%; padding: 0.65rem 1rem; font-size: 0.875rem; font-weight: 600; color: white; background: #E87450; border: none; border-radius: 0.5rem; cursor: pointer; box-shadow: 0 4px 14px rgba(232, 116, 80, 0.35); margin-bottom: 1rem; transition: all 0.3s ease; }
        .btn:hover { background: #C85A38; transform: scale(1.05); }

        .btn-outline { width: 100%; padding: 0.65rem 1rem; font-size: 0.875rem; font-weight: 600; color: #E87450; background: transparent; border: 1px solid rgba(232,116,80,0.4); border-radius: 0.5rem; cursor: pointer; margin-bottom: 1rem; }

        .auth-footer { text-align: center; font-size: 0.875rem; color: #706f6c; margin-top: 0.5rem; }
        .auth-footer .link { color: #E87450; text-decoration: none; font-weight: 600; }

        .error-box { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 0.6rem 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.8125rem; line-height: 1.5; }
        .error-box.success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .error-box ul { list-style: none; padding: 0; margin: 0; }
        .error-box li { margin-bottom: 0.25rem; }
        .error-box li:last-child { margin-bottom: 0; }

        @keyframes spin-slow { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes spin-slow-reverse { from { transform: rotate(360deg); } to { transform: rotate(0deg); } }
        @keyframes blob { 0%, 100% { transform: translate(0, 0) scale(1); } 25% { transform: translate(20px, -30px) scale(1.1); } 50% { transform: translate(-20px, 20px) scale(0.95); } 75% { transform: translate(30px, 10px) scale(1.05); } }
    </style>
</head>
<body>
    <canvas id="networkCanvas"></canvas>

    <div class="floating-shapes">
        <div class="circle circle-1"></div>
        <div class="circle circle-2"></div>
        <div class="circle circle-3"></div>
        <div class="circle circle-4"></div>
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="auth-container" id="app" data-page='@json($page ?? [])'>
        {{ $slot }}
    </div>

    <script>
    function togglePasswordVisibility(btn) {
        var wrap = btn.closest('.pwd-group');
        var input = wrap.querySelector('input');
        var eyeOpen = btn.querySelector('.eye-open');
        var eyeClosed = btn.querySelector('.eye-closed');
        if (input.type === 'password') { input.type = 'text'; eyeOpen.style.display = 'none'; eyeClosed.style.display = 'inline'; }
        else { input.type = 'password'; eyeOpen.style.display = 'inline'; eyeClosed.style.display = 'none'; }
    }
    </script>

    <script>
    (function() {
        const canvas = document.getElementById('networkCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let nodes = [];
        let mouse = { x: -1000, y: -1000 };
        let animationId;

        function resizeCanvas() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }

        function initNodes() {
            const nodeCount = 50;
            nodes = [];
            for (let i = 0; i < nodeCount; i++) {
                nodes.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.5,
                    vy: (Math.random() - 0.5) * 0.5,
                    radius: Math.random() * 3 + 2,
                    opacity: Math.random() * 0.5 + 0.3,
                    pulsePhase: Math.random() * Math.PI * 2
                });
            }
        }

        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const time = Date.now() * 0.001;

            nodes.forEach((node, i) => {
                node.x += node.vx;
                node.y += node.vy;

                if (node.x < 0 || node.x > canvas.width) node.vx *= -1;
                if (node.y < 0 || node.y > canvas.height) node.vy *= -1;

                node.x = Math.max(0, Math.min(canvas.width, node.x));
                node.y = Math.max(0, Math.min(canvas.height, node.y));

                const dx = node.x - mouse.x;
                const dy = node.y - mouse.y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < 150 && dist > 0) {
                    const force = (150 - dist) / 150 * 0.02;
                    node.vx += (dx / dist) * force;
                    node.vy += (dy / dist) * force;
                }

                const speed = Math.sqrt(node.vx * node.vx + node.vy * node.vy);
                if (speed > 1) { node.vx /= speed; node.vy /= speed; }

                const pulse = Math.sin(time * 2 + node.pulsePhase) * 0.3 + 0.7;

                for (let j = i + 1; j < nodes.length; j++) {
                    const other = nodes[j];
                    const cdx = other.x - node.x;
                    const cdy = other.y - node.y;
                    const distance = Math.sqrt(cdx * cdx + cdy * cdy);

                    if (distance < 150) {
                        const opacity = (1 - distance / 150) * 0.15 * pulse;
                        ctx.beginPath();
                        ctx.moveTo(node.x, node.y);
                        ctx.lineTo(other.x, other.y);
                        ctx.strokeStyle = `rgba(232, 116, 80, ${opacity})`;
                        ctx.lineWidth = 1;
                        ctx.stroke();
                    }
                }

                const gradient = ctx.createRadialGradient(node.x, node.y, 0, node.x, node.y, node.radius * 2);
                gradient.addColorStop(0, `rgba(232, 116, 80, ${node.opacity * pulse})`);
                gradient.addColorStop(1, 'rgba(232, 116, 80, 0)');

                ctx.beginPath();
                ctx.arc(node.x, node.y, node.radius * pulse, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(232, 116, 80, ${node.opacity * pulse})`;
                ctx.fill();

                ctx.beginPath();
                ctx.arc(node.x, node.y, node.radius * 2 * pulse, 0, Math.PI * 2);
                ctx.fillStyle = gradient;
                ctx.fill();
            });

            animationId = requestAnimationFrame(animate);
        }

        resizeCanvas();
        initNodes();
        animate();

        window.addEventListener('resize', function() {
            resizeCanvas();
            initNodes();
        });

        window.addEventListener('mousemove', function(e) {
            mouse.x = e.clientX;
            mouse.y = e.clientY;
        });

        window.addEventListener('mouseleave', function() {
            mouse.x = -1000;
            mouse.y = -1000;
        });
    })();
    </script>
</body>
</html>
