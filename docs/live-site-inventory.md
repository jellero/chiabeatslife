# Inventario verificato del sito live

Sorgente analizzata: `https://chiabeatslife.jacopoellero.it/`.

Questo file contiene esclusivamente URL e asset osservati direttamente dalla home pubblica prima del cut-over. Il crawler `tools/import_wordpress.py` estende automaticamente questo inventario con sitemap, articoli, paginazioni e asset delle sottopagine.

## Route pubbliche visibili dalla navigazione

- `/` — home / Chi sono
- `/la-mia-storia/`
- `/formati-con-me/`
- `/risorse-per-te/`
- `/blog/`
- `/contatti/`

## Categorie blog visibili dalla home

- `/category/medicina/`
- `/category/esperienze-allestero/`
- `/category/nutrizione/`
- `/category/sport/`

## Policy visibili dalla home

- `/privacy-policy/`
- `/cookie-policy/`
- `/terms-conditions/`

## Asset immagine verificati sulla home

- `/wp-content/uploads/2025/08/Layer_1-2.png`
- `/wp-content/uploads/2025/11/Rectangle-1-.jpeg`
- `/wp-content/uploads/2025/08/Vector-11-scaled.png`
- `/wp-content/uploads/2025/08/Vector-12-scaled.png`
- `/wp-content/uploads/2025/11/Rectangle1.jpeg`
- `/wp-content/uploads/2025/08/Vector-13.png`
- `/wp-content/uploads/2025/10/Rectangle-13.png`
- `/wp-content/uploads/2025/10/Rectangle-14.png`
- `/wp-content/uploads/2025/10/Rectangle-15.png`
- `/wp-content/uploads/2025/10/Rectangle-16.png`
- `/wp-content/uploads/2025/10/Ellipse-6.png`
- `/wp-content/uploads/2025/11/Ellipse.webp`

## Contenuti/struttura verificati sulla home

La home presenta:

- hero `chiabeatslife` / `non la classica med student`;
- introduzione `Benvenuto in @chiabeatslife`;
- sezione passioni con Sport, Alimentazione e Viaggi;
- sezione `Un approccio diverso`;
- testimonianza community;
- sezione `Un obiettivo reale e concreto`;
- footer con quick links, policy, Instagram, TikTok e LinkedIn.

## Nota tecnica

Le sottopagine sono raggiungibili dal sito live ma il crawler web usato durante l'analisi iniziale non ne ha restituito il sorgente completo. Per questo motivo non sono stati inventati HTML, CSS o JavaScript: l'import definitivo è delegato al crawler eseguito da un ambiente con accesso HTTP diretto al sito, che salva gli asset originali e genera le route Slim.
