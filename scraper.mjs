import express from 'express';
import puppeteer from 'puppeteer-core';

const CHROME_PATH = '/usr/bin/chromium';

const app = express();
app.use(express.json({ limit: '10mb' }));

app.post('/scrape', async (req, res) => {
    const { url } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'URL is required' });
    }

    try {
        const data = await scrapeYandexMaps(url);
        res.json(data);
    } catch (err) {
        res.status(500).json({ error: err.message, stack: err.stack });
    }
});

async function scrapeYandexMaps(yandexUrl) {
    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-web-security',
        ],
    });

    try {
        const page = await browser.newPage();
        await page.setUserAgent(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'
        );
        await page.setViewport({ width: 1440, height: 900 });

        await page.setExtraHTTPHeaders({
            'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.5',
        });

        await page.goto(yandexUrl, { waitUntil: 'networkidle0', timeout: 90000 });
        await new Promise(r => setTimeout(r, 3000));

        // extract org info from page
        const pageData = await page.evaluate(() => {
            const ogTitle = document.querySelector('meta[property="og:title"]')?.getAttribute('content') || '';
            const h1 = document.querySelector('h1')?.textContent?.trim() || '';

            let title = '';
            if (h1 && h1.length <= 60) title = h1;
            else if (ogTitle) title = ogTitle.replace(/ — Яндекс Карты$/, '').trim();

            const addressMeta = document.querySelector('meta[property="og:description"]')?.getAttribute('content') || '';
            let address = '';
            if (addressMeta) {
                const m = addressMeta.match(/Адрес:\s*(.+?)(?:\.|$)/);
                if (m) address = m[1].trim();
            }
            if (!address) {
                const addrEl = document.querySelector('[class*="business-contacts-view__address"], [class*="business-card-address"]');
                if (addrEl) address = addrEl.textContent?.trim() || '';
            }

            const badge = document.querySelector('[class*="business-rating-badge-view__rating-text"]');
            let rating = 0, ratingsCount = 0;
            if (badge) rating = parseFloat((badge.textContent || '').trim().replace(',', '.'));
            const meta = document.querySelector('[itemProp="ratingValue"]');
            if (meta) rating = parseFloat((meta.getAttribute('content') || '0').replace(',', '.'));
            const countMeta = document.querySelector('[itemProp="ratingCount"]');
            if (countMeta) ratingsCount = parseInt(countMeta.getAttribute('content') || '0');

            // extract reviews from DOM
            const reviewEls = document.querySelectorAll('[class*="business-review-view"]');
            const domReviews = Array.from(reviewEls).map(el => {
                const textEl = el.querySelector('[class*="business-review-view__body"]');
                const authorEl = el.querySelector('[itemProp="name"]');
                const ratingEl = el.querySelector('[aria-label*="Rating"], [aria-label*="Рейтинг"], [aria-label*="Оценка"]');
                const dateEl = el.querySelector('[itemProp="datePublished"]');
                const starsEl = el.querySelector('[class*="business-rating-badge-view__stars"]');
                let rRating = 0;
                if (ratingEl) {
                    const m = ratingEl.getAttribute('aria-label')?.match(/(\d+)\s*(?:Out\s+of|из|Of)/i);
                    if (m) rRating = parseInt(m[1]);
                } else if (starsEl) {
                    rRating = starsEl.querySelectorAll('[class*="_full"]').length;
                }
                return {
                    author: authorEl?.textContent?.trim() || '',
                    date: dateEl?.getAttribute('content') || null,
                    rating: rRating,
                    text: textEl?.textContent?.trim() || '',
                };
            }).filter(r => r.text.length > 10);

            return { title, address, rating, ratingsCount, domReviews };
        });

        let allReviews = pageData.domReviews || [];

        // try navigating to reviews page
        const reviewsPageUrl = yandexUrl.replace(/\/?(\?.*)?$/, '/reviews/');
        const allReviewItems = [];
        const seenTexts = new Set();

        const extractReviewsFromDom = () => page.evaluate(() => {
            const items = document.querySelectorAll('[class*="business-review-view"]');
            return Array.from(items).map(el => {
                const textEl = el.querySelector('[class*="business-review-view__body"]');
                const authorEl = el.querySelector('[itemProp="name"]');
                const ratingEl = el.querySelector('[aria-label*="Rating"], [aria-label*="Рейтинг"], [aria-label*="Оценка"]');
                const dateEl = el.querySelector('[itemProp="datePublished"]');
                const starsEl = el.querySelector('[class*="business-rating-badge-view__stars"]');
                let r = 0;
                if (ratingEl) {
                    const m = ratingEl.getAttribute('aria-label')?.match(/(\d+)\s*(?:Out\s+of|из|Of)/i);
                    if (m) r = parseInt(m[1]);
                } else if (starsEl) {
                    r = starsEl.querySelectorAll('[class*="_full"]').length;
                }
                return {
                    author: authorEl?.textContent?.trim() || '',
                    date: dateEl?.getAttribute('content') || null,
                    rating: r,
                    text: textEl?.textContent?.trim() || '',
                };
            }).filter(rr => rr.text.length > 10);
        });

        const dedupAndAdd = (reviews) => {
            for (const r of reviews) {
                const key = (r.text || '').substring(0, 100);
                if (!seenTexts.has(key)) {
                    seenTexts.add(key);
                    allReviewItems.push(r);
                }
            }
        };

        try {
            await page.goto(reviewsPageUrl, { waitUntil: 'networkidle0', timeout: 30000 });
            await new Promise(r => setTimeout(r, 4000));

            // scroll page 1 to load all reviews
            let prevCount = 0;
            for (let i = 0; i < 80; i++) {
                await page.evaluate(() => {
                    const container = document.querySelector('.scroll__container');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                });
                await new Promise(r => setTimeout(r, 1500));

                const batch = await extractReviewsFromDom();
                if (i > 3 && batch.length === prevCount) break;
                prevCount = batch.length;
            }

            dedupAndAdd(await extractReviewsFromDom());

            // try navigating to subsequent pages
            for (let pg = 0; pg < 5; pg++) {
                const hasNext = await page.evaluate(() => {
                    const links = document.querySelectorAll('a');
                    for (const link of links) {
                        const t = link.textContent?.trim() || '';
                        if (t === 'Далее' || t === 'Next' || t === 'Следующая') {
                            link.click();
                            return true;
                        }
                    }
                    return false;
                });

                if (!hasNext) break;
                await new Promise(r => setTimeout(r, 6000));

                // scroll page N to load all reviews
                prevCount = 0;
                for (let i = 0; i < 40; i++) {
                    await page.evaluate(() => {
                        const container = document.querySelector('.scroll__container');
                        if (container) {
                            container.scrollTop = container.scrollHeight;
                        }
                    });
                    await new Promise(r => setTimeout(r, 1500));

                    const batch = await extractReviewsFromDom();
                    if (i > 3 && batch.length === prevCount) break;
                    prevCount = batch.length;
                }
                dedupAndAdd(await extractReviewsFromDom());
            }
        } catch (e) {
            console.error('Reviews page error:', e.message?.substring(0, 100));
        }

        if (allReviewItems.length > 0) {
            allReviews = allReviewItems;
        }

        const reviews = allReviews.map((r, i) => ({
            author: r.author || 'Anonymous',
            date: r.date || null,
            text: r.text || '',
            rating: r.rating || 0,
            external_id: String(i + 1),
        }));

        return {
            org: { title: pageData.title, address: pageData.address },
            rating: pageData.rating,
            ratings_count: pageData.ratingsCount,
            reviews_count: reviews.length,
            reviews,
            url: yandexUrl,
        };
    } finally {
        await browser.close();
    }
}

const PORT = process.env.SCRAPER_PORT || 3099;
app.listen(PORT, '0.0.0.0', () => {
    console.log(`Scraper running on http://0.0.0.0:${PORT}`);
});
