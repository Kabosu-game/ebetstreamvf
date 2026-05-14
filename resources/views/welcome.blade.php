<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'eBetStream') }}</title>
    <style>
        :root {
            color-scheme: dark;
            --ink: #f7fafc;
            --muted: #aeb8c8;
            --line: rgba(255, 255, 255, .12);
            --panel: rgba(14, 20, 32, .76);
            --green: #42e8a2;
            --cyan: #4cc9f0;
            --gold: #ffcf5a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 18% 18%, rgba(66, 232, 162, .18), transparent 28rem),
                radial-gradient(circle at 86% 10%, rgba(76, 201, 240, .20), transparent 24rem),
                linear-gradient(135deg, #07090f 0%, #111827 52%, #080a10 100%);
        }

        main {
            min-height: 100vh;
            display: grid;
            align-items: center;
            padding: 28px;
        }

        .wrap {
            width: min(1120px, 100%);
            margin: 0 auto;
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 42px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 900;
        }

        .mark {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            color: #06130d;
            background: linear-gradient(135deg, var(--green), var(--cyan));
            font-weight: 950;
        }

        .badge {
            border: 1px solid rgba(66, 232, 162, .35);
            color: var(--green);
            background: rgba(66, 232, 162, .1);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 800;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.08fr .92fr;
            gap: 24px;
            align-items: stretch;
        }

        .intro, .panel {
            border: 1px solid var(--line);
            background: var(--panel);
            backdrop-filter: blur(18px);
            box-shadow: 0 24px 80px rgba(0, 0, 0, .34);
            border-radius: 8px;
        }

        .intro {
            min-height: 500px;
            padding: 44px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        h1 {
            margin: 0 0 20px;
            max-width: 760px;
            font-size: clamp(42px, 7vw, 84px);
            line-height: .95;
            letter-spacing: 0;
        }

        .lead {
            margin: 0;
            max-width: 650px;
            color: var(--muted);
            font-size: 18px;
            line-height: 1.7;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 34px;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .button {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border-radius: 8px;
            border: 1px solid var(--line);
            font-weight: 900;
        }

        .button.primary {
            color: #06130d;
            border-color: transparent;
            background: linear-gradient(135deg, var(--green), var(--gold));
        }

        .panel {
            padding: 30px;
            display: grid;
            gap: 14px;
            align-content: center;
        }

        .tile {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 20px;
            background: rgba(255, 255, 255, .045);
        }

        .tile strong {
            display: block;
            margin-bottom: 8px;
            font-size: 18px;
        }

        .tile span {
            display: block;
            color: var(--muted);
            line-height: 1.55;
        }

        @media (max-width: 860px) {
            main { padding: 18px; }
            .nav { align-items: flex-start; flex-direction: column; }
            .hero { grid-template-columns: 1fr; }
            .intro { min-height: auto; padding: 30px; }
        }
    </style>
</head>
<body>
    <main>
        <div class="wrap">
            <header class="nav">
                <div class="brand">
                    <div class="mark">eB</div>
                    <span>{{ config('app.name', 'eBetStream') }}</span>
                </div>
                <div class="badge">Plateforme en ligne</div>
            </header>

            <section class="hero">
                <div class="intro">
                    <h1>Bienvenue sur eBetStream.</h1>
                    <p class="lead">
                        Suivez les compétitions, rejoignez votre communauté et profitez d’une expérience simple, rapide et pensée pour les joueurs.
                    </p>
                    <div class="actions">
                        <a class="button primary" href="http://localhost:5174/">Ouvrir l’application</a>
                    </div>
                </div>

                <aside class="panel" aria-label="Fonctionnalités">
                    <div class="tile">
                        <strong>Compétitions</strong>
                        <span>Découvrez les matchs, championnats et événements disponibles.</span>
                    </div>
                    <div class="tile">
                        <strong>Communauté</strong>
                        <span>Retrouvez les clans, joueurs, streams et échanges autour du jeu.</span>
                    </div>
                    <div class="tile">
                        <strong>Profil joueur</strong>
                        <span>Gérez votre progression, vos défis et vos activités depuis l’application.</span>
                    </div>
                </aside>
            </section>
        </div>
    </main>
</body>
</html>
