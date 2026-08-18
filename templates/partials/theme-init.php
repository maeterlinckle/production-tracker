<script>
(function () {
    var stored = null;
    try { stored = localStorage.getItem('theme'); } catch (e) {}
    if (!stored) {
        var match = document.cookie.match(/(?:^|; )theme=([^;]+)/);
        stored = match ? decodeURIComponent(match[1]) : null;
    }
    if (!stored) {
        stored = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', stored);
})();
</script>
