/**
 * Render ```mermaid fenced blocks promoted to .mermaid divs in .markdown-body.
 */
(function () {
    'use strict';

    function upgradeLegacyCodeBlocks(root) {
        root.querySelectorAll('pre code.language-mermaid').forEach(function (code) {
            var pre = code.parentElement;
            if (!pre || !pre.parentNode) {
                return;
            }
            var div = document.createElement('div');
            div.className = 'mermaid st-mermaid-diagram';
            div.setAttribute('role', 'figure');
            div.setAttribute('aria-label', 'Diagram');
            div.textContent = code.textContent || '';
            pre.parentNode.replaceChild(div, pre);
        });
    }

    function initMermaid() {
        if (typeof mermaid === 'undefined') {
            return;
        }
        var roots = document.querySelectorAll('.markdown-body');
        if (!roots.length) {
            return;
        }
        roots.forEach(upgradeLegacyCodeBlocks);
        var nodes = document.querySelectorAll('.markdown-body .mermaid');
        if (!nodes.length) {
            return;
        }
        mermaid.initialize({
            startOnLoad: false,
            theme: 'neutral',
            securityLevel: 'strict',
            fontFamily: 'inherit',
            flowchart: { useMaxWidth: true, htmlLabels: true }
        });
        mermaid.run({ nodes: nodes }).catch(function (err) {
            console.warn('Sanctum Tasks: Mermaid render failed', err);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMermaid);
    } else {
        initMermaid();
    }
})();
