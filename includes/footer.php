<?php if (!empty($authPage)): /* the auth page closed its own main */ ?>
<?php elseif ($private): ?>
</section>
    </main>
</div>
<?php else: ?>
</section>
</main>
<?php endif; ?>
<aside class="ai-assistant" data-ai-assistant aria-label="Acme HR assistant"><button class="ai-orb" data-ai-toggle aria-expanded="false" aria-label="Open Acme HR assistant"><img class="ai-orb-art" src="assets/ai-launcher.png" alt="" aria-hidden="true"><span class="ai-tab-label">Acme Assist</span></button><div class="ai-panel" data-ai-panel aria-hidden="true"><div class="ai-panel-inner"><div class="ai-head"><span class="ai-icon-wrap"><img class="ai-icon" src="assets/ai-agent.png" alt="Acme Assist"></span><div><strong>Acme Assist</strong><small><span class="ai-dot"></span>HR helper · ready</small></div><button class="ai-close" data-ai-toggle aria-label="Close assistant">×</button></div><div class="ai-messages" data-ai-messages><div class="ai-message bot"><img class="ai-msg-avatar" src="assets/ai-agent.png" alt=""><p>Hi! I can help you find roles, check leave, or explain HR workflows.</p></div></div><form class="ai-form" data-ai-form><input name="message" placeholder="Ask about HR..." autocomplete="off" aria-label="Message Acme Assist"><button type="submit" aria-label="Send message">➤</button></form></div></div></aside><div id="toast" class="toast" role="status"></div><script src="assets/app.js?v=<?= @filemtime(__DIR__.'/../assets/app.js') ?: time() ?>"></script></body></html>
