// Captura as páginas de política de uma loja (entrega, termos, trocas) em PDF, com a
// URL e a data/hora no cabeçalho, e devolve o texto de cada uma. Chamado pelo
// App\Services\Stores\NodeStoreCapturer: entrada JSON no argv[2], saída JSON no stdout.
//
// Requer playwright-core e o chromium-headless-shell em MED_BROWSER_DIR (storage/browser):
//   npm i playwright-core@1.49.1 && npx playwright@1.49.1 install chromium-headless-shell
const path = require('path');
const net = require('net');

const input = JSON.parse(process.argv[2] || '{}');
const browserDir = process.env.MED_BROWSER_DIR || '';
const { chromium } = require(path.join(browserDir, 'node_modules', 'playwright-core'));

const KINDS = {
    shipping: {
        match: /(entrega|envio|frete|prazo|shipping|delivery)/i,
        fallbacks: ['/policies/shipping-policy', '/pages/politica-de-entrega', '/pages/politica-de-envio', '/politica-de-entrega', '/politica-de-envio'],
    },
    terms: {
        match: /(termos|terms|condi[cç][oõ]es de uso|condi[cç][oõ]es gerais)/i,
        fallbacks: ['/policies/terms-of-service', '/pages/termos-de-uso', '/termos-de-uso', '/termos'],
    },
    exchange: {
        match: /(troca|devolu|reembols|refund|return)/i,
        fallbacks: ['/policies/refund-policy', '/pages/trocas-e-devolucoes', '/pages/politica-de-troca', '/trocas-e-devolucoes'],
    },
};

// Bloqueia rede interna: a URL vem de cadastro de terceiro.
function isPrivateHost(hostname) {
    const host = hostname.replace(/^\[|\]$/g, '').toLowerCase();
    if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.internal') || host.endsWith('.local')) return true;
    if (!net.isIP(host)) return false;
    if (net.isIPv6(host)) return host === '::1' || host.startsWith('fc') || host.startsWith('fd') || host.startsWith('fe80');
    const [a, b] = host.split('.').map(Number);
    return a === 10 || a === 127 || a === 0 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127);
}

function allowed(url) {
    try {
        const u = new URL(url);
        return (u.protocol === 'http:' || u.protocol === 'https:') && !isPrivateHost(u.hostname);
    } catch {
        return false;
    }
}

function stamp() {
    return new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}

async function open(page, url, timeout) {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
    await page.waitForLoadState('networkidle', { timeout: Math.min(timeout, 10000) }).catch(() => {});
    return response;
}

// Pop-ups de cupom, região e cookies cobrem a política no PDF: fecha com Esc e esconde
// diálogo e camada fixa que cubra boa parte da tela.
async function clearOverlays(page) {
    await page.keyboard.press('Escape').catch(() => {});
    await page.evaluate(() => {
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        for (const el of document.querySelectorAll('body *')) {
            const style = getComputedStyle(el);
            const dialog = el.getAttribute('role') === 'dialog' || el.getAttribute('aria-modal') === 'true' || el.tagName === 'DIALOG';
            if (!dialog && style.position !== 'fixed' && style.position !== 'sticky') continue;
            const r = el.getBoundingClientRect();
            const big = r.width * r.height > vw * vh * 0.15;
            const banner = style.position === 'fixed' && r.width > vw * 0.6 && (r.top < 5 || r.bottom > vh - 5) && r.height < vh * 0.4;
            if (dialog || big || banner) el.style.setProperty('display', 'none', 'important');
        }
        document.documentElement.style.overflow = 'visible';
        document.body.style.overflow = 'visible';
    }).catch(() => {});
}

async function bodyText(page) {
    return (await page.evaluate(() => document.body ? document.body.innerText : '')).replace(/\n{3,}/g, '\n\n').trim();
}

(async () => {
    const result = { pages: [], errors: [], website: input.website, final_url: null };
    const timeout = input.timeout_ms || 30000;

    if (!allowed(input.website)) {
        result.errors.push('URL da loja inválida ou interna.');
        process.stdout.write(JSON.stringify(result));
        return;
    }

    const browser = await chromium.launch();

    try {
        const context = await browser.newContext({
            locale: 'pt-BR',
            viewport: { width: 1280, height: 900 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
        });
        await context.route('**/*', (route) => (allowed(route.request().url()) ? route.continue() : route.abort()));
        const page = await context.newPage();

        await open(page, input.website, timeout);
        result.final_url = page.url();
        const base = new URL(page.url());
        // Página sem query nem âncora: link de política que volta para a página inicial
        // (loja de página única com "Termos" no rodapé apontando para ela mesma) não é
        // política, e a mesma página não vale para dois tipos.
        const pageKey = (url) => { const u = new URL(url); return u.origin.replace('://www.', '://') + u.pathname.replace(/\/+$/, ''); };
        const homeKey = pageKey(page.url());
        const usedKeys = new Set();

        const links = await page.$$eval('a[href]', (as) => as.map((a) => ({ href: a.href, text: (a.innerText || a.title || '').trim() })));
        const sameSite = links.filter((l) => {
            try { return new URL(l.href).hostname.replace(/^www\./, '') === base.hostname.replace(/^www\./, ''); } catch { return false; }
        });

        for (const [kind, rule] of Object.entries(KINDS)) {
            const given = input[`${kind}_url`];
            const candidates = [];

            if (given) candidates.push(given);
            const byText = sameSite.find((l) => rule.match.test(l.text)) || sameSite.find((l) => rule.match.test(decodeURIComponent(new URL(l.href).pathname)));
            if (byText) candidates.push(byText.href);
            for (const fallback of rule.fallbacks) candidates.push(new URL(fallback, base).toString());

            let done = false;
            for (const url of [...new Set(candidates)]) {
                if (done || !allowed(url)) continue;
                try {
                    const response = await open(page, url, timeout);
                    if (!response || response.status() >= 400) continue;
                    const key = pageKey(page.url());
                    if ((key === homeKey && url !== given) || usedKeys.has(key)) continue;
                    const text = await bodyText(page);
                    if (text.length < 200 || !rule.match.test(text)) continue;

                    const file = path.join(input.out_dir, `${kind}.pdf`);
                    await page.waitForTimeout(1500);
                    await clearOverlays(page);
                    await page.emulateMedia({ media: 'screen' });
                    await page.pdf({
                        path: file,
                        format: 'A4',
                        printBackground: true,
                        displayHeaderFooter: true,
                        margin: { top: '22mm', bottom: '14mm', left: '8mm', right: '8mm' },
                        headerTemplate: `<div style="font-size:8px;width:100%;padding:0 8mm;color:#444">Página capturada pela ÚnicoPag em ${stamp()} (horário de Brasília) - ${page.url().replace(/</g, '&lt;')}</div>`,
                        footerTemplate: '<div style="font-size:8px;width:100%;text-align:center;color:#444"><span class="pageNumber"></span>/<span class="totalPages"></span></div>',
                    });

                    result.pages.push({ kind, url: page.url(), title: await page.title(), file, text: text.slice(0, 20000) });
                    usedKeys.add(key);
                    done = true;
                } catch (e) {
                    result.errors.push(`${kind}: ${String(e.message || e).slice(0, 200)}`);
                }
            }

            if (!done) result.errors.push(`${kind}: página não encontrada (link ausente ou que volta para a página inicial)`);
        }
    } catch (e) {
        result.errors.push(String(e.message || e).slice(0, 300));
    } finally {
        await browser.close();
    }

    process.stdout.write(JSON.stringify(result));
})();
