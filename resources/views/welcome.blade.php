<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Castilo - Connect, Create, Grow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #FFFBF8 0%, #FFF8F5 50%, #FFF5F0 100%);
            color: #1b1b18;
            overflow-x: hidden;
        }

        /* Network Canvas */
        #networkCanvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        /* Floating Shapes */
        .floating-shapes {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 1;
        }

        .circle {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(232, 116, 80, 0.1);
        }

        .circle-1 {
            width: 24rem;
            height: 24rem;
            top: -5rem;
            left: -5rem;
            animation: spin-slow 30s linear infinite;
        }

        .circle-2 {
            width: 18rem;
            height: 18rem;
            top: -2.5rem;
            left: -2.5rem;
            animation: spin-slow-reverse 25s linear infinite;
        }

        .circle-3 {
            width: 20rem;
            height: 20rem;
            bottom: -5rem;
            right: -5rem;
            animation: spin-slow 30s linear infinite;
        }

        .circle-4 {
            width: 16rem;
            height: 16rem;
            bottom: -2.5rem;
            right: -2.5rem;
            animation: spin-slow-reverse 25s linear infinite;
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
        }

        .blob-1 {
            width: 16rem;
            height: 16rem;
            background: linear-gradient(135deg, rgba(232, 116, 80, 0.2), transparent);
            top: 25%;
            left: 25%;
            animation: blob 15s ease-in-out infinite;
        }

        .blob-2 {
            width: 18rem;
            height: 18rem;
            background: linear-gradient(135deg, rgba(244, 162, 97, 0.15), transparent);
            top: 33%;
            right: 25%;
            animation: blob 15s ease-in-out infinite 2s;
        }

        .blob-3 {
            width: 14rem;
            height: 14rem;
            background: linear-gradient(135deg, rgba(232, 116, 80, 0.15), transparent);
            bottom: 25%;
            left: 33%;
            animation: blob 15s ease-in-out infinite 4s;
        }

        /* Layout */
        .container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 10;
        }

        /* Header */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
        }

        @media (min-width: 1024px) {
            header {
                padding: 1rem 3rem;
            }
        }

        nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #1b1b18;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .nav-link:hover {
            color: #E87450;
        }

        .btn-primary {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            background: #E87450;
            color: white;
            border-radius: 0.5rem;
            text-decoration: none;
            box-shadow: 0 10px 25px -5px rgba(232, 116, 80, 0.25);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #C85A38;
            transform: scale(1.05);
            box-shadow: 0 10px 25px -5px rgba(232, 116, 80, 0.4);
        }

        /* Main Content */
        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 1.5rem;
        }

        @media (min-width: 1024px) {
            main {
                padding: 1.5rem 3rem;
            }
        }

        /* Features Grid */
        .features-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            max-width: 72rem;
            margin: 0 auto;
            width: 100%;
        }

        @media (min-width: 768px) {
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .features-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.5);
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(20px);
            animation: fade-in-up 0.6s ease-out forwards;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -10px rgba(232, 116, 80, 0.1);
            border-color: rgba(232, 116, 80, 0.3);
            background: rgba(255, 255, 255, 0.9);
        }

        .feature-card:hover .feature-icon {
            background: #E87450;
            transform: scale(1.1);
        }

        .feature-card:hover .feature-icon svg {
            stroke: white;
        }

        .feature-card:hover .feature-link svg {
            transform: translateX(4px);
        }

        .feature-icon {
            width: 2.75rem;
            height: 2.75rem;
            background: #FFF5F0;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .feature-icon svg {
            width: 1.25rem;
            height: 1.25rem;
            stroke: #E87450;
            stroke-width: 2;
            fill: none;
            transition: stroke 0.3s ease;
        }

        .feature-title {
            font-weight: 600;
            font-size: 1rem;
            color: #1b1b18;
            margin-bottom: 0.25rem;
        }

        .feature-description {
            color: #706f6c;
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .feature-link {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            color: #E87450;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: gap 0.3s ease;
        }

        .feature-link:hover {
            gap: 0.625rem;
        }

        .feature-link svg {
            width: 1rem;
            height: 1rem;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            transition: transform 0.3s ease;
        }

        /* Footer */
        footer {
            padding: 1rem 1.5rem;
            text-align: center;
            font-size: 0.875rem;
            color: #706f6c;
        }

        @media (min-width: 1024px) {
            footer {
                padding: 1rem 3rem;
            }
        }

        /* Animations */
        @keyframes spin-slow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes spin-slow-reverse {
            from { transform: rotate(360deg); }
            to { transform: rotate(0deg); }
        }

        @keyframes blob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(20px, -30px) scale(1.1); }
            50% { transform: translate(-20px, 20px) scale(0.95); }
            75% { transform: translate(30px, 10px) scale(1.05); }
        }

        @keyframes fade-in-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
 <body>
        @vite('resources/css/app.css')

    <!-- Network Animation Canvas -->
    <canvas id="networkCanvas"></canvas>

    <!-- Floating Shapes -->
    <div class="floating-shapes">
        <div class="circle circle-1"></div>
        <div class="circle circle-2"></div>
        <div class="circle circle-3"></div>
        <div class="circle circle-4"></div>
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <!-- Main Container -->
    <div class="container" id="app" data-page='@json($page)'>
        <!-- Header -->
        <header>
            <a href="#">
                <img src="{{ asset('img/logo.png') }}" alt="" width=200>
            </a>
            <nav>
                @auth
                    <a href="{{ url('dashboard') }}" class="btn-primary">Dashboard</a>
                @else
                    <a href="{{ url('login') }}" class="nav-link">Sign In</a>
                    <a href="{{ url('register') }}" class="btn-primary">Get Started</a>
                @endauth
            </nav>
        </header>

        <!-- Main Content -->
        <main>
            <div class="features-grid">
                <!-- Post a Job -->
                <div class="feature-card" style="animation-delay: 0s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    </div>
                    <h3 class="feature-title">Post a Job</h3>
                    <p class="feature-description">Reach talented professionals and find the perfect candidate for your team.</p>
                    <a href="#" class="feature-link">
                        Post Now
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Post Events -->
                <div class="feature-card" style="animation-delay: 0.08s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                    </div>
                    <h3 class="feature-title">Post Events</h3>
                    <p class="feature-description">Create and promote events to engage your community and grow your audience.</p>
                    <a href="#" class="feature-link">
                        Create Event
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Post Products -->
                <div class="feature-card" style="animation-delay: 0.16s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </div>
                    <h3 class="feature-title">Post Products</h3>
                    <p class="feature-description">Showcase your products to thousands of potential buyers in our marketplace.</p>
                    <a href="#" class="feature-link">
                        List Product
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Buy Products -->
                <div class="feature-card" style="animation-delay: 0.24s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                    </div>
                    <h3 class="feature-title">Buy Products</h3>
                    <p class="feature-description">Discover unique products from sellers around the world at great prices.</p>
                    <a href="#" class="feature-link">
                        Shop Now
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Apply for a Job -->
                <div class="feature-card" style="animation-delay: 0.32s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>
                    </div>
                    <h3 class="feature-title">Apply for a Job</h3>
                    <p class="feature-description">Find your dream job and connect with top employers in your field.</p>
                    <a href="#" class="feature-link">
                        Browse Jobs
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Book Events -->
                <div class="feature-card" style="animation-delay: 0.4s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M2 10s3-3 8-3 8 3 8 3"/><path d="M2 14s3 3 8 3 8-3 8-3"/><path d="M9 22V2"/><path d="M15 22V2"/></svg>
                    </div>
                    <h3 class="feature-title">Book Events</h3>
                    <p class="feature-description">Participate in exciting events or book exclusive experiences for yourself.</p>
                    <a href="#" class="feature-link">
                        Explore Events
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Go Live -->
                <div class="feature-card" style="animation-delay: 0.48s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="m22 8-6 4 6 4V8Z"/><rect width="14" height="12" x="2" y="6" rx="2" ry="2"/></svg>
                    </div>
                    <h3 class="feature-title">Go Live</h3>
                    <p class="feature-description">Stream live content and connect with your audience in real-time.</p>
                    <a href="#" class="feature-link">
                        Start Stream
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Messaging -->
                <div class="feature-card" style="animation-delay: 0.56s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
                    </div>
                    <h3 class="feature-title">Messaging</h3>
                    <p class="feature-description">Connect and communicate with others through our messaging system.</p>
                    <a href="#" class="feature-link">
                        Open Chat
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

                <!-- Follow Blogs -->
                <div class="feature-card" style="animation-delay: 0.64s;">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3 class="feature-title">Follow Blogs</h3>
                    <p class="feature-description">Stay updated with content from creators you love and follow their journey.</p>
                    <a href="#" class="feature-link">
                        Discover
                        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer>
            <p>Connect. Create. Grow. — Castilo v1.0</p>
        </footer>
    </div>

    <!-- Network Animation Script -->
    <script>
        (function() {
            const canvas = document.getElementById('networkCanvas');
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
                    // Move nodes
                    node.x += node.vx;
                    node.y += node.vy;

                    // Bounce off edges
                    if (node.x < 0 || node.x > canvas.width) node.vx *= -1;
                    if (node.y < 0 || node.y > canvas.height) node.vy *= -1;

                    // Keep in bounds
                    node.x = Math.max(0, Math.min(canvas.width, node.x));
                    node.y = Math.max(0, Math.min(canvas.height, node.y));

                    // Mouse interaction
                    const dx = node.x - mouse.x;
                    const dy = node.y - mouse.y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < 150 && dist > 0) {
                        const force = (150 - dist) / 150 * 0.02;
                        node.vx += (dx / dist) * force;
                        node.vy += (dy / dist) * force;
                    }

                    // Limit velocity
                    const speed = Math.sqrt(node.vx * node.vx + node.vy * node.vy);
                    if (speed > 1) {
                        node.vx = (node.vx / speed);
                        node.vy = (node.vy / speed);
                    }

                    // Pulsing effect
                    const pulse = Math.sin(time * 2 + node.pulsePhase) * 0.3 + 0.7;

                    // Draw connections
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

                    // Draw node
                    const gradient = ctx.createRadialGradient(
                        node.x, node.y, 0,
                        node.x, node.y, node.radius * 2
                    );
                    gradient.addColorStop(0, `rgba(232, 116, 80, ${node.opacity * pulse})`);
                    gradient.addColorStop(1, 'rgba(232, 116, 80, 0)');

                    ctx.beginPath();
                    ctx.arc(node.x, node.y, node.radius * pulse, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(232, 116, 80, ${node.opacity * pulse})`;
                    ctx.fill();

                    // Outer glow
                    ctx.beginPath();
                    ctx.arc(node.x, node.y, node.radius * 2 * pulse, 0, Math.PI * 2);
                    ctx.fillStyle = gradient;
                    ctx.fill();
                });

                animationId = requestAnimationFrame(animate);
            }

            // Initialize
            resizeCanvas();
            initNodes();
            animate();

            // Event listeners
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