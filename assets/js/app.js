/**
 * KhataShata - Core Interactive Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Theme toggle
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    const currentTheme = localStorage.getItem('khatashata_theme') || 'light';
    document.documentElement.setAttribute('data-theme', currentTheme);

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const activeTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = activeTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('khatashata_theme', newTheme);
        });
    }

    // 2. Auto-dismiss alerts after 6 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 400);
        }, 6000);
    });

    // 3. Dynamic ownership percentage calculator on ownership config forms
    const pctInputs = document.querySelectorAll('.ownership-pct-input');
    const totalDisplay = document.getElementById('ownership-total-pct');
    const submitBtn = document.getElementById('ownership-submit-btn');

    if (pctInputs.length > 0 && totalDisplay) {
        function calculateTotal() {
            let total = 0;
            pctInputs.forEach(input => {
                const val = parseFloat(input.value) || 0;
                total += val;
            });
            totalDisplay.textContent = total.toFixed(2) + '%';
            if (Math.abs(total - 100.0) < 0.001) {
                totalDisplay.style.color = 'var(--success-dark)';
                if (submitBtn) submitBtn.disabled = false;
            } else {
                totalDisplay.style.color = 'var(--danger-dark)';
                if (submitBtn) submitBtn.disabled = true;
            }
        }
        pctInputs.forEach(inp => inp.addEventListener('input', calculateTotal));
        calculateTotal();
    }
});

// Generic AJAX helper
async function apiRequest(url, data = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    };
    if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
    }

    const response = await fetch(url, {
        method: 'POST',
        headers: headers,
        body: JSON.stringify(data)
    });
    return response.json();
}
