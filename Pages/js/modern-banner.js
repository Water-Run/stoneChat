(function() {
  function checkModernBrowser() {
    if (typeof window.addEventListener === 'undefined') return false;
    if (typeof document.querySelector === 'undefined') return false;
    if (typeof Promise === 'undefined') return false;
    if (typeof fetch === 'undefined') return false;
    if (typeof document.createElement === 'undefined') return false;
    return true;
  }

  function hasModernWindowsCookie() {
    var cookie = '';
    try {
      cookie = String(document.cookie || '');
    } catch (e) {
      cookie = '';
    }
    return ('; ' + cookie + ';').indexOf('; sc_modern=1;') !== -1;
  }

  if (!hasModernWindowsCookie()) {
    return;
  }

  if (!checkModernBrowser()) {
    return;
  }

  // Load Outfit Google Font for modern look
  var fontLink = document.createElement('link');
  fontLink.rel = 'stylesheet';
  fontLink.href = 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap';
  document.getElementsByTagName('head')[0].appendChild(fontLink);

  // Ingest modern styles
  var style = document.createElement('style');
  style.type = 'text/css';
  var css = 
    '@keyframes scSlideDown { from { transform: translate(-50%, -40px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }' +
    '@keyframes scGlow { 0% { box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5), 0 0 10px rgba(99, 102, 241, 0.2); } 50% { box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5), 0 0 20px rgba(99, 102, 241, 0.4), 0 0 30px rgba(244, 63, 94, 0.15); } 100% { box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5), 0 0 10px rgba(99, 102, 241, 0.2); } }' +
    '.sc-modern-banner {' +
    '  font-family: "Outfit", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;' +
    '  position: fixed;' +
    '  top: 16px;' +
    '  left: 50%;' +
    '  transform: translate(-50%, 0);' +
    '  width: 90%;' +
    '  max-width: 680px;' +
    '  background: rgba(15, 23, 42, 0.85);' +
    '  backdrop-filter: blur(12px);' +
    '  -webkit-backdrop-filter: blur(12px);' +
    '  border: 1px solid rgba(255, 255, 255, 0.12);' +
    '  border-radius: 16px;' +
    '  padding: 14px 22px;' +
    '  color: #f8fafc;' +
    '  z-index: 999999;' +
    '  display: flex;' +
    '  align-items: center;' +
    '  justify-content: space-between;' +
    '  animation: scSlideDown 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards, scGlow 4s infinite ease-in-out;' +
    '  transition: opacity 0.4s ease, transform 0.4s ease;' +
    '  box-sizing: border-box;' +
    '}' +
    '.sc-modern-banner-content {' +
    '  display: flex;' +
    '  align-items: center;' +
    '  gap: 12px;' +
    '  flex: 1;' +
    '}' +
    '.sc-modern-banner-icon {' +
    '  font-size: 20px;' +
    '  background: linear-gradient(135deg, #818cf8, #f472b6);' +
    '  -webkit-background-clip: text;' +
    '  -webkit-text-fill-color: transparent;' +
    '  display: inline-block;' +
    '  line-height: 1;' +
    '}' +
    '.sc-modern-banner-text {' +
    '  font-size: 13.5px;' +
    '  font-weight: 400;' +
    '  letter-spacing: 0.2px;' +
    '  line-height: 1.45;' +
    '}' +
    '.sc-modern-banner-text strong {' +
    '  font-weight: 600;' +
    '  background: linear-gradient(135deg, #a5b4fc, #fbcfe8);' +
    '  -webkit-background-clip: text;' +
    '  -webkit-text-fill-color: transparent;' +
    '}' +
    '.sc-modern-banner-close {' +
    '  background: transparent;' +
    '  border: none;' +
    '  color: #94a3b8;' +
    '  cursor: pointer;' +
    '  font-size: 22px;' +
    '  padding: 0 4px 0 12px;' +
    '  line-height: 1;' +
    '  transition: all 0.2s;' +
    '  display: flex;' +
    '  align-items: center;' +
    '  justify-content: center;' +
    '}' +
    '.sc-modern-banner-close:hover {' +
    '  color: #f1f5f9;' +
    '  transform: scale(1.15);' +
    '}';

  if (style.styleSheet) {
    style.styleSheet.cssText = css;
  } else {
    style.appendChild(document.createTextNode(css));
  }
  document.getElementsByTagName('head')[0].appendChild(style);

  function showBanner() {
    var banner = document.createElement('div');
    banner.className = 'sc-modern-banner';
    banner.innerHTML = 
      '<div class="sc-modern-banner-content">' +
      '  <span class="sc-modern-banner-icon">✨</span>' +
      '  <div class="sc-modern-banner-text">' +
      '    <strong>Modern Environment Active:</strong> Premium styling and high-performance Web-API dispatch enabled.' +
      '  </div>' +
      '</div>' +
      '<button type="button" class="sc-modern-banner-close" aria-label="Close">×</button>';

    var closeBtn = banner.querySelector('.sc-modern-banner-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function() {
        banner.style.opacity = '0';
        banner.style.transform = 'translate(-50%, -20px)';
        setTimeout(function() {
          if (banner.parentNode) {
            banner.parentNode.removeChild(banner);
          }
        }, 400);
      });
    }

    document.body.appendChild(banner);
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    showBanner();
  } else {
    document.addEventListener('DOMContentLoaded', showBanner);
  }
})();
