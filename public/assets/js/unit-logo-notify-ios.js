document.addEventListener('DOMContentLoaded', function() {
    var notifyBtn = document.getElementById('unit-logo-notify-ios-btn');
    if (!notifyBtn) {
        return;
    }

    notifyBtn.addEventListener('click', async function() {
        if (!confirm('Envoyer une notification à tous les comptes pour les inviter à réinstaller l\'application sur iOS ?')) {
            return;
        }

        var csrf = document.querySelector('meta[name="csrf-token"]');
        var res = await fetch('/config/settings/logo-notify-ios', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf_token: csrf ? csrf.content : '' })
        });
        var data = await res.json();

        if (data.success) {
            notifyBtn.disabled = true;
            notifyBtn.textContent = 'Notification envoyée';
        } else {
            alert(data.error || 'Une erreur est survenue.');
        }
    });
});
