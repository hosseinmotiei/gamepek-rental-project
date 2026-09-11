{{--
    The GamePek design language — single source of truth.

    In the Store this block was duplicated in three places (the public layout,
    the admin layout and the standalone login page) and had already drifted:
    the login copy was missing brandLightBlue and flashRed. Included here by
    every layout instead, so Rental and Store stay visually identical and the
    palette can never fork again.

    Values are unchanged from GamePek Store.

    Note: Tailwind and Vazirmatn load from CDN, matching the Store's
    no-build-step convention. See README for why this is flagged as a
    production concern for Iranian hosting.
--}}
<script src="https://cdn.tailwindcss.com?plugins=typography"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    brandBlue: '#0066FF',
                    brandDark: '#111111',
                    brandGray: '#F5F5F5',
                    brandLightBlue: '#E6F0FF',
                    flashRed: '#EF4056',
                    sidebar: '#0F1729'
                },
                fontFamily: {
                    sans: ['Vazirmatn', 'sans-serif'],
                }
            }
        }
    }
</script>
<style>
    /* overflow-x deliberately NOT on body -- iOS Safari has a well-known bug
       where overflow-x:hidden directly on <body> breaks position:fixed for
       any descendant (it scrolls with the page instead of staying pinned to
       the viewport, exactly what broke the product page's fixed price/buy
       bar). Applied to <main> instead, which still stops horizontal bleed
       from page content without triggering that bug. */
    body { font-family: 'Vazirmatn', sans-serif; background-color: #F5F5F5; color: #111111; scroll-behavior: smooth; overscroll-behavior-y: none; }
    main { overflow-x: hidden; }
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    /* Accent underline for active tabs. In the Store this exact rule was
       copy-pasted verbatim into three separate page views under three
       different class names (.sort-tab-active, .tab-active). One definition. */
    .tab-active { border-bottom: 3px solid #0066FF; color: #0066FF; font-weight: 700; }

    /* Keyboard focus. Many controls use `outline-none` for mouse users; nothing
       gave keyboard users a visible focus at all. :focus-visible applies only to
       keyboard (and other non-pointer) focus, so mouse clicks look unchanged. */
    :focus-visible { outline: 2px solid #0066FF !important; outline-offset: 2px !important; }
</style>
