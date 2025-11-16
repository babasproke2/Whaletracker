<div class="tab-content active" id="tab-chat">
    <div id="chat-container">
        <div id="chat-panel" class="players-list">
            <div id="chat-messages" class="chat-messages" aria-live="polite"></div>
        </div>
        <form id="chat-form" class="chat-form" autocomplete="off">
            <input type="text" id="chat-input" name="message" data-dynamic-placeholder="Type to {count} players | All messages are deleted after 24hrs" placeholder="Type to 0 players | All messages are deleted after 24hrs" maxlength="180" required />
            <button type="submit" id="chat-send">Send</button>
        </form>
        <div id="chat-status" class="chat-status" role="status" aria-live="polite"></div>
    </div>
</div>
