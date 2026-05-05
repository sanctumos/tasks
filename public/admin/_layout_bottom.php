</main>
<div id="st-toast-host" class="st-toast-host" aria-live="polite" aria-atomic="true"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/admin.js?v=3"></script>
<script>
    // Pretty-print datetime-local fields with stored UTC values
    document.querySelectorAll('input[type="datetime-local"][data-utc]').forEach(function(el){
        var v = el.getAttribute('data-utc');
        if (v) {
            try { el.value = v.replace(' ', 'T').slice(0, 16); } catch(e) {}
        }
    });
</script>
</body>
</html>
