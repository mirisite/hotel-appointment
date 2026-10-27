<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173b35">
    <meta name="description" content="Discover a more considered hotel experience with The Queens Hotel. Find a room, plan your stay, and manage your booking in one place.">
    <title>The Queens Hotel | A stay, made simple</title>
    <style>
        :root {
            color-scheme: light;
            --forest: #173b35;
            --forest-deep: #102d29;
            --paper: #fbfaf7;
            --ink: #202923;
            --muted: #727a73;
            --gold: #e0c291;
            --line: #dfe3dc;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            min-width: 320px;
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: "Aptos", "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        .hero {
            position: relative;
            display: flex;
            min-height: min(790px, 100svh);
            flex-direction: column;
            overflow: hidden;
            padding: 0 clamp(24px, 7vw, 104px) 42px;
            background-color: var(--forest);
            background-image: linear-gradient(90deg, rgba(10, 34, 30, 0.72), rgba(10, 34, 30, 0.18) 78%), linear-gradient(0deg, rgba(10, 34, 30, 0.58), transparent 38%), url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=2200&q=90");
            background-position: center;
            background-size: cover;
            color: #fff;
            animation: arrive 700ms ease-out both;
        }

        .topbar {
            display: flex;
            min-height: 96px;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.27);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            font-size: 14px;
            font-weight: 650;
            letter-spacing: 0.08em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .brand-mark {
            display: grid;
            width: 36px;
            aspect-ratio: 1;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.75);
            color: var(--gold);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 20px;
            font-weight: 400;
        }

        .nav-actions { display: flex; align-items: center; gap: 28px; }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            font-size: 13px;
            text-decoration-color: rgba(255, 255, 255, 0.5);
            text-underline-offset: 5px;
        }

        .nav-cta, .hero-cta {
            display: inline-flex;
            min-height: 48px;
            align-items: center;
            justify-content: center;
            gap: 14px;
            border: 1px solid #fff;
            background: #fff;
            color: var(--forest);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 160ms ease, color 160ms ease, transform 160ms ease;
        }

        .nav-cta { min-height: 42px; padding: 0 18px; }
        .hero-cta { min-height: 54px; padding: 0 22px; }

        .nav-cta:hover, .hero-cta:hover {
            transform: translateY(-1px);
            background: var(--forest-deep);
            color: #fff;
        }

        .hero-content {
            width: min(100%, 700px);
            margin: auto 0;
            padding: 80px 0 96px;
            animation: arrive 700ms 100ms ease-out both;
        }

        .eyebrow {
            margin: 0 0 20px;
            color: var(--gold);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        h1, h2, h3 { font-family: Georgia, "Times New Roman", serif; font-weight: 400; }

        h1 {
            max-width: 670px;
            margin: 0;
            font-size: clamp(52px, 7vw, 88px);
            line-height: 0.99;
        }

        .hero-content > p:not(.eyebrow) {
            max-width: 440px;
            margin: 24px 0 30px;
            color: rgba(255, 255, 255, 0.83);
            font-size: 16px;
            line-height: 1.75;
        }

        .hero-cta span { font-size: 18px; line-height: 1; }

        .hero-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
        }

        .hero-foot span:last-child { color: var(--gold); letter-spacing: 0.12em; }

        .highlights {
            padding: 86px clamp(24px, 7vw, 104px) 96px;
            background: var(--paper);
        }

        .highlights-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 32px;
            margin-bottom: 42px;
        }

        .highlights-heading .eyebrow { margin-bottom: 12px; color: #8b7049; }

        h2 {
            max-width: 490px;
            margin: 0;
            font-size: clamp(34px, 4vw, 48px);
            line-height: 1.1;
        }

        .highlights-heading > p {
            max-width: 370px;
            margin: 0 0 3px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            border-top: 1px solid var(--line);
        }

        .feature {
            min-height: 190px;
            padding: 25px 28px 0 0;
        }

        .feature + .feature { padding-left: 28px; border-left: 1px solid var(--line); }

        .feature-number {
            color: #8b7049;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 15px;
        }

        h3 { margin: 22px 0 9px; font-size: 25px; }

        .feature p {
            max-width: 330px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 22px clamp(24px, 7vw, 104px);
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
        }

        .footer a { color: var(--forest); font-weight: 650; text-underline-offset: 3px; }

        a:focus-visible { outline: 3px solid var(--gold); outline-offset: 4px; }

        @keyframes arrive {
            from { opacity: 0; transform: translateY(9px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 700px) {
            .hero { min-height: 720px; min-height: min(760px, 100svh); padding-bottom: 26px; background-position: 58% center; }
            .topbar { min-height: 78px; }
            .brand { gap: 9px; font-size: 12px; }
            .brand-mark { width: 32px; }
            .nav-actions { gap: 16px; }
            .nav-link { display: none; }
            .nav-cta { min-height: 39px; padding: 0 13px; font-size: 12px; }
            .hero-content { padding: 72px 0; }
            h1 { max-width: 520px; font-size: clamp(50px, 13vw, 72px); }
            .hero-content > p:not(.eyebrow) { max-width: 360px; font-size: 15px; }
            .highlights { padding-top: 64px; padding-bottom: 68px; }
            .highlights-heading { display: block; margin-bottom: 32px; }
            .highlights-heading > p { margin-top: 16px; }
            .feature-grid { grid-template-columns: 1fr; }
            .feature, .feature + .feature { min-height: auto; padding: 22px 0; border-left: 0; }
            .feature + .feature { border-top: 1px solid var(--line); }
            h3 { margin-top: 12px; }
            .footer { align-items: flex-start; flex-direction: column; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
    <main>
        <section class="hero" aria-labelledby="hero-title">
            <header class="topbar">
                <a class="brand" href="index.php" aria-label="The Queens Hotel home">
                    <span class="brand-mark" aria-hidden="true">Q</span>
                    <span>The Queens Hotel</span>
                </a>
                <nav class="nav-actions" aria-label="Main navigation">
                    <a class="nav-link" href="#experience">Our experience</a>
                    <a class="nav-cta" href="login.php">Sign in <span aria-hidden="true">&#8594;</span></a>
                </nav>
            </header>

            <div class="hero-content">
                <p class="eyebrow">A considered kind of stay</p>
                <h1 id="hero-title">Hospitality, made personal.</h1>
                <p>Find your room, plan your dates, and keep every detail of your stay together in one thoughtful place.</p>
                <a class="hero-cta" href="login.php">Plan your stay <span aria-hidden="true">&#8594;</span></a>
            </div>

            <div class="hero-foot">
                <span>Make yourself at home.</span>
                <span>THE QUEENS HOTEL</span>
            </div>
        </section>

        <section class="highlights" id="experience" aria-labelledby="highlights-title">
            <div class="highlights-heading">
                <div>
                    <p class="eyebrow">Your stay, in good hands</p>
                    <h2 id="highlights-title">Every detail, in one place.</h2>
                </div>
                <p>From choosing a room to checking your reservation, your hotel experience is simple to manage from the moment you sign in.</p>
            </div>

            <div class="feature-grid">
                <article class="feature">
                    <span class="feature-number">01</span>
                    <h3>Find your room</h3>
                    <p>Explore room options and check availability for the dates and guests that suit you.</p>
                </article>
                <article class="feature">
                    <span class="feature-number">02</span>
                    <h3>Book with ease</h3>
                    <p>Choose your stay details and a convenient payment option when you make a reservation.</p>
                </article>
                <article class="feature">
                    <span class="feature-number">03</span>
                    <h3>Stay in control</h3>
                    <p>Guests can review booking details, while our management team can access dedicated tools.</p>
                </article>
            </div>
        </section>
    </main>

    <footer class="footer">
        <span>&copy; <?= date('Y') ?> The Queens Hotel</span>
        <a href="login.php">Customer sign in</a>
        <a href="management-login.php">Management sign in</a>
    </footer>
</body>
</html>