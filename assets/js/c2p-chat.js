(function ($) {
    'use strict';

    if ('undefined' === typeof window.c2pChatSettings) {
        return;
    }

    var settings = window.c2pChatSettings;

    function Chat($wrapper) {
        this.$wrapper = $wrapper;
        this.$messages = $wrapper.find('.c2p-chat__messages');
        this.$form = $wrapper.find('.c2p-chat__form');
        this.$input = $wrapper.find('.c2p-chat__input');
        this.$matchSelector = $wrapper.find('.c2p-chat__match-selector');
        this.$sendButton = $wrapper.find('.c2p-chat__button');

        this.matchId = parseInt(this.$messages.data('match-id'), 10) || 0;
        this.competitionId = parseInt(this.$wrapper.data('competition-id'), 10) || 0;
        this.afterId = 0;
        this.timer = null;
        this.isSending = false;

        this.init();
    }

    Chat.prototype.init = function () {
        var self = this;

        this.$form.on('submit', function (event) {
            event.preventDefault();
            self.send();
        });

        if (this.$matchSelector.length) {
            this.$matchSelector.on('change', function () {
                self.matchId = parseInt(self.$matchSelector.val(), 10) || 0;
                self.afterId = 0;
                self.$messages.empty().attr('data-match-id', self.matchId);
                self.fetch();
            });
        }

        this.fetch();
        this.startPolling();
    };

    Chat.prototype.startPolling = function () {
        var self = this;
        if (this.timer) {
            clearInterval(this.timer);
        }

        this.timer = setInterval(function () {
            self.fetch();
        }, settings.pollInterval || 5000);
    };

    Chat.prototype.send = function () {
        var self = this;
        var message = $.trim(this.$input.val());

        if (!message || this.isSending) {
            return;
        }

        this.isSending = true;
        this.$sendButton.prop('disabled', true);

        $.ajax({
            url: settings.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'c2p_chat_send_message',
                nonce: settings.nonce,
                match_id: this.matchId,
                competition_id: this.competitionId,
                message: message
            }
        }).done(function (response) {
            if (response && response.success && response.data && response.data.message) {
                self.appendMessage(response.data.message);
                self.$input.val('');
            } else if (response && response.data && response.data.message) {
                window.alert(response.data.message);
            }
        }).fail(function () {
            window.alert('Message could not be sent.');
        }).always(function () {
            self.isSending = false;
            self.$sendButton.prop('disabled', false);
        });
    };

    Chat.prototype.fetch = function () {
        var self = this;

        if (!this.matchId) {
            return;
        }

        $.ajax({
            url: settings.ajaxUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'c2p_chat_fetch_messages',
                nonce: settings.nonce,
                match_id: this.matchId,
                competition_id: this.competitionId,
                after_id: this.afterId
            }
        }).done(function (response) {
            if (response && response.success && response.data && response.data.messages) {
                response.data.messages.forEach(function (message) {
                    self.appendMessage(message);
                });
            } else if (response && response.data && response.data.message) {
                window.alert(response.data.message);
            }
        });
    };

    Chat.prototype.appendMessage = function (message) {
        this.afterId = Math.max(this.afterId, parseInt(message.id, 10) || 0);

        var $message = $('<div/>', { 'class': 'c2p-chat__message' });
        var $header = $('<div/>', { 'class': 'c2p-chat__message-meta' });
        var $author = $('<span/>', { 'class': 'c2p-chat__message-author', text: message.user_name });
        var date = new Date(message.created_at + 'Z');
        var timeString = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        var $time = $('<time/>', { 'class': 'c2p-chat__message-time', text: timeString });

        $header.append($author).append($time);

        var $body = $('<div/>', { 'class': 'c2p-chat__message-body', text: message.message });

        $message.append($header).append($body);
        this.$messages.append($message);
        this.$messages.scrollTop(this.$messages[0].scrollHeight);
    };

    $(function () {
        $('.c2p-chat').each(function () {
            new Chat($(this));
        });
    });
})(jQuery);
