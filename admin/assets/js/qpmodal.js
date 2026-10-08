/*========================================================
 * 弹出框
 * =======================================================*/
// TODO F7有些方法没实现,暂时不需要
;
(function ($, window, document, undefined) {
    var qpmodalStack = [];
    var _qpmodalTemplateTempDiv = document.createElement('div');

    function qpmodal(params) {
        params = params || {};
        var qpmodalHTML = '';
        var buttonsHTML = '';
        if (params.buttons && params.buttons.length > 0) {
            for (var i = 0; i < params.buttons.length; i++) {
                buttonsHTML += '<span class="qpmodal-button' + (params.buttons[i].bold ? ' qpmodal-button-bold' : '') + '">' + params.buttons[i].text + '</span>';
            }
        }
        var titleHTML = params.title ? '<div class="qpmodal-title">' + params.title + '</div>' : '';
        var textHTML = params.text ? '<div class="qpmodal-text">' + params.text + '</div>' : '';
        var afterTextHTML = params.afterText ? params.afterText : '';
        var noButtons = !params.buttons || params.buttons.length === 0 ? 'qpmodal-no-buttons' : '';
        var verticalButtons = params.verticalButtons ? 'qpmodal-buttons-vertical' : '';
        qpmodalHTML = '<div class="qpmodal ' + noButtons + ' ' + (params.cssClass || '') + '"><div class="qpmodal-inner">' + (titleHTML + textHTML + afterTextHTML) + '</div><div class="qpmodal-buttons ' + verticalButtons + '">' + buttonsHTML + '</div></div>';
        _qpmodalTemplateTempDiv.innerHTML = qpmodalHTML;
        var qpmodal = $(_qpmodalTemplateTempDiv).children();
        $('body').append(qpmodal[0]);
        // Add events on buttons
        qpmodal.find('.qpmodal-button').each(function (index, el) {
            $(el).on('click', function (e) {
                if (params.buttons[index].close !== false) closeQPModal(qpmodal);
                if (params.buttons[index].onClick) params.buttons[index].onClick(qpmodal, e);
                if (params.onClick) params.onClick(qpmodal, index);
            });
        });
        openQPModal(qpmodal);
        return qpmodal;
    }

    function openQPModal(qpmodal) {
        qpmodal = $(qpmodal);
        var isQPModal = qpmodal.hasClass('qpmodal');
        if ($('.qpmodal.qpmodal-in:not(.qpmodal-out)').length && isQPModal) {
            qpmodalStack.push(function () {
                openQPModal(qpmodal);
            });
            return;
        }
        // do nothing if this qpmodal already shown
        if (true === qpmodal.data('f7-qpmodal-shown')) {
            return;
        }
        qpmodal.data('f7-qpmodal-shown', true);
        qpmodal.trigger('close', function () {
            qpmodal.removeData('f7-qpmodal-shown');
        });
        if (isQPModal) {
            qpmodal.show();
            qpmodal.css({
                marginTop: -Math.round(qpmodal.outerHeight() / 2) + 'px'
            });
        }
        if ($('.qpmodal-overlay').length === 0) {
            $('body').append('<div class="qpmodal-overlay"></div>');
        }
        var overlay = $('.qpmodal-overlay');
        //Make sure that styles are applied, trigger relayout;
        var clientLeft = qpmodal[0].clientLeft;//这个不能删,删了actions动画没了.
        // Trugger open event
        qpmodal.trigger('open');
        // Classes for transition in
        overlay.addClass('qpmodal-overlay-visible');
        qpmodal.removeClass('qpmodal-out').addClass('qpmodal-in').transitionEnd(function (e) {
            if (qpmodal.hasClass('qpmodal-out')) qpmodal.trigger('closed');
            else qpmodal.trigger('opened');
        });
        return true;
    }

    function closeQPModal(qpmodal) {
        qpmodal = $(qpmodal || '.qpmodal-in');
        if (typeof qpmodal !== 'undefined' && qpmodal.length === 0) {
            return;
        }
        var isQPModal = qpmodal.hasClass('qpmodal');
        var overlay = $('.qpmodal-overlay');
        if (overlay && overlay.length > 0) {
            overlay.removeClass('qpmodal-overlay-visible');
        }
        qpmodal.trigger('close');
        qpmodal.removeClass('qpmodal-in').addClass('qpmodal-out').transitionEnd(function (e) {
            if (qpmodal.hasClass('qpmodal-out')) qpmodal.trigger('closed');
            else qpmodal.trigger('opened');
            qpmodal.remove();
        });
        if (isQPModal) {
            qpmodalStackClearQueue();
        }
        return true;
    }

    function qpmodalStackClearQueue() {
        if (qpmodalStack.length) {
            (qpmodalStack.shift())();
        }
    }

    var qpmodalTitle = '';
    var qpmodalButtonOk = 'Ok';
    var qpmodalButtonCancel = 'Cancel';
    var qpmodalPreloaderTitle = 'Loading...';
    $.extend({
        prompt: function (value, title, callbackOk, callbackCancel) {
            if (arguments.length === 2) {
                callbackOk = arguments[1];
                title = arguments[0];
                value = '';
            }
            var m = qpmodal({
                text: '<input class="qpmodal-input" value="'+value+'"/>',
                title: typeof title === 'undefined' ? qpmodalTitle : title,
                buttons: [
                    {text: qpmodalButtonCancel, onClick: callbackCancel},
                    {text: qpmodalButtonOk, bold: true, onClick: function(){
                        var value = $('.qpmodal-input').val();
                        callbackOk && callbackOk(value);
                    }}
                ]
            });
            m.on('opened', function(){
                var $input = $('.qpmodal-input');
                $input.focus();
                var input = $input.get(0);
                var value = $input.val();
                var valueLength = value ? value.length : 0;
                input.setSelectionRange && input.setSelectionRange(valueLength,valueLength);
            });
            return m;
        },
        alert: function (text, title, status, callbackOk) {
            // Backwards compatible: title optional, status optional
            if (typeof title === 'function') {
                callbackOk = title;
                title = undefined;
                status = '';
            }
            // If status omitted but callback provided in third arg
            if (typeof status === 'function') {
                callbackOk = status;
                status = '';
            }
            var m = qpmodal({
                text: text || '',
                title: typeof title === 'undefined' ? qpmodalTitle : title,
                buttons: [
                    {text: qpmodalButtonOk, bold: true, onClick: callbackOk}
                ]
            });
            if (status) {
                try {
                    var s = String(status).toLowerCase();
                    if (s === 'error' || s === 'success' || s === 'warning') {
                        m.find('.qpmodal-inner').addClass('qpmd-' + s);
                    }
                } catch (e) {}
            }
            return m;
        },
        confirm: function (text, title, callbackOk, callbackCancel) {
            if (typeof title === 'function') {
                callbackCancel = arguments[2];
                callbackOk = arguments[1];
                title = undefined;
            }
            return qpmodal({
                text: text || '',
                title: typeof title === 'undefined' ? qpmodalTitle : title,
                buttons: [
                    {text: qpmodalButtonCancel, onClick: callbackCancel},
                    {text: qpmodalButtonOk, bold: true, onClick: callbackOk}
                ]
            });
        },
        showPreloader: function (title) {
            return qpmodal({
                title: title || qpmodalPreloaderTitle,
                text: '<div class="preloader"></div>',
                cssClass: 'qpmodal-preloader'
            });
        },
        hidePreloader: function () {
            closeQPModal('.qpmodal.qpmodal-in');
        },
        showIndicator: function () {
            //$('body').append('<div class="preloader-indicator-overlay"></div><div class="preloader-indicator-qpmodal"><span class="preloader preloader-white"></span></div>');
            //去掉全屏透明遮盖层
            $('body').append('<div class="preloader-indicator-qpmodal"><span class="preloader preloader-white"></span></div>');
        },
        hideIndicator: function () {
            $('.preloader-indicator-overlay, .preloader-indicator-qpmodal').remove();
        },
        toast: function (text, during, closeCallBack) {

            if (typeof during === 'function') {
                closeCallBack = arguments[1];
                during = undefined;
            }

            if (!during) {
                during = 1500;
            }

            var m = qpmodal({
                title: '',
                text: text
            });
            if (closeCallBack) {
                m.on("close", closeCallBack);
            }
            setTimeout(function () {
                closeQPModal();
            }, during);
            return qpmodal
        },
        actions: function (params) {
            var qpmodal, groupSelector, buttonSelector;
            params = params || [];
            if (params.length > 0 && !$.isArray(params[0])) {
                params = [params];
            }
            var qpmodalHTML;
            var buttonsHTML = '';
            for (var i = 0; i < params.length; i++) {
                for (var j = 0; j < params[i].length; j++) {
                    if (j === 0) buttonsHTML += '<div class="actions-qpmodal-group">';
                    var button = params[i][j];
                    var buttonClass = button.label ? 'actions-qpmodal-label' : 'actions-qpmodal-button';
                    if (button.bold) buttonClass += ' actions-qpmodal-button-bold';
                    if (button.color) buttonClass += ' color-' + button.color;
                    if (button.bg) buttonClass += ' bg-' + button.bg;
                    if (button.disabled) buttonClass += ' disabled';
                    buttonsHTML += '<div class="' + buttonClass + '">' + button.text + '</div>';
                    if (j === params[i].length - 1) buttonsHTML += '</div>';
                }
            }
            qpmodalHTML = '<div class="actions-qpmodal">' + buttonsHTML + '</div>';
            _qpmodalTemplateTempDiv.innerHTML = qpmodalHTML;
            qpmodal = $(_qpmodalTemplateTempDiv).children();
            $('body').append(qpmodal[0]);
            groupSelector = '.actions-qpmodal-group';
            buttonSelector = '.actions-qpmodal-button';
            var groups = qpmodal.find(groupSelector);
            groups.each(function (index, el) {
                var groupIndex = index;
                $(el).children().each(function (index, el) {
                    var buttonIndex = index;
                    var buttonParams = params[groupIndex][buttonIndex];
                    var clickTarget;
                    if ($(el).is(buttonSelector)) clickTarget = $(el);
                    if ($(el).find(buttonSelector).length > 0) clickTarget = $(el).find(buttonSelector);
                    if (clickTarget) {
                        clickTarget.on('click', function (e) {
                            if (buttonParams.close !== false) closeQPModal(qpmodal);
                            if (buttonParams.onClick) buttonParams.onClick(qpmodal, e);
                        });
                    }
                });
            });
            openQPModal(qpmodal);
            return qpmodal;
        },
        closeQPModal: closeQPModal
    });
})(jQuery, window, document);


/*========================================================
 * 一些基础工具封装
 * =======================================================*/
$.extend({
    device: (function () {
        var device = {};
        var ua = navigator.userAgent;
        var android = ua.match(/(Android);?[\s\/]+([\d.]+)?/);
        var ipad = ua.match(/(iPad).*OS\s([\d_]+)/);
        var ipod = ua.match(/(iPod)(.*OS\s([\d_]+))?/);
        var iphone = !ipad && ua.match(/(iPhone\sOS)\s([\d_]+)/);

        device.ios = device.android = device.iphone = device.ipad = device.androidChrome = false;

        // Android
        if (android) {
            device.os = 'android';
            device.osVersion = android[2];
            device.android = true;
            device.androidChrome = ua.toLowerCase().indexOf('chrome') >= 0;
        }
        if (ipad || iphone || ipod) {
            device.os = 'ios';
            device.ios = true;
        }
        // iOS
        if (iphone && !ipod) {
            device.osVersion = iphone[2].replace(/_/g, '.');
            device.iphone = true;
        }
        if (ipad) {
            device.osVersion = ipad[2].replace(/_/g, '.');
            device.ipad = true;
        }
        if (ipod) {
            device.osVersion = ipod[3] ? ipod[3].replace(/_/g, '.') : null;
            device.iphone = true;
        }
        // iOS 8+ changed UA
        if (device.ios && device.osVersion && ua.indexOf('Version/') >= 0) {
            if (device.osVersion.split('.')[0] === '10') {
                device.osVersion = ua.toLowerCase().split('version/')[1].split(' ')[0];
            }
        }

        // Webview
        device.webView = (iphone || ipad || ipod) && ua.match(/.*AppleWebKit(?!.*Safari)/i);

        // Minimal UI
        if (device.os && device.os === 'ios') {
            var osVersionArr = device.osVersion.split('.');
            device.minimalUi = !device.webView &&
                (ipod || iphone) &&
                (osVersionArr[0] * 1 === 7 ? osVersionArr[1] * 1 >= 1 : osVersionArr[0] * 1 > 7) &&
                $('meta[name="viewport"]').length > 0 && $('meta[name="viewport"]').attr('content').indexOf('minimal-ui') >= 0;
        }

        // Check for status bar and fullscreen app mode
        var windowWidth = $(window).width();
        var windowHeight = $(window).height();
        device.statusBar = false;
        if (device.webView && (windowWidth * windowHeight === screen.width * screen.height)) {
            device.statusBar = true;
        } else {
            device.statusBar = false;
        }

        // Classes
        var classNames = [];

        // Pixel Ratio
        device.pixelRatio = window.devicePixelRatio || 1;
        classNames.push('pixel-ratio-' + Math.floor(device.pixelRatio));
        if (device.pixelRatio >= 2) {
            classNames.push('retina');
        }

        // OS classes
        if (device.os) {
            classNames.push(device.os, device.os + '-' + device.osVersion.split('.')[0], device.os + '-' + device.osVersion.replace(/\./g, '-'));
            if (device.os === 'ios') {
                var major = parseInt(device.osVersion.split('.')[0], 10);
                for (var i = major - 1; i >= 6; i--) {
                    classNames.push('ios-gt-' + i);
                }
            }

        }
        // Status bar classes
        if (device.statusBar) {
            classNames.push('with-statusbar-overlay');
        } else {
            $('html').removeClass('with-statusbar-overlay');
        }

        // Add html classes
        if (classNames.length > 0) $('html').addClass(classNames.join(' '));
        device.wx = ua.toLowerCase().indexOf('micromessenger') >= 0;
        // Export object
        return device;
    })()
});

$.extend({
    support: {
        touch: !!(('ontouchstart' in window) || window.DocumentTouch && document instanceof DocumentTouch)
    }
});
$.extend({
    touchEvents: {
        start: $.support.touch ? 'touchstart' : 'mousedown',
        move: $.support.touch ? 'touchmove' : 'mousemove',
        end: $.support.touch ? 'touchend' : 'mouseup'
    }
});
$.extend({
    compareVersion: function (a, b) {
        if (a === b) return 0;
        var as = a.split('.');
        var bs = b.split('.');
        for (var i = 0; i < as.length; i++) {
            var x = parseInt(as[i]);
            if (!bs[i]) return 1;
            var y = parseInt(bs[i]);
            if (x < y) return -1;
            if (x > y) return 1;
        }
        return 1;
    }
});

$.fn.transform = function (transform) {
    for (var i = 0; i < this.length; i++) {
        var elStyle = this[i].style;
        elStyle.webkitTransform = elStyle.MsTransform = elStyle.msTransform = elStyle.MozTransform = elStyle.OTransform = elStyle.transform = transform;
    }
    return this;
};
$.fn.transition = function (duration) {
    if (typeof duration !== 'string') {
        duration = duration + 'ms';
    }
    for (var i = 0; i < this.length; i++) {
        var elStyle = this[i].style;
        elStyle.webkitTransitionDuration = elStyle.MsTransitionDuration = elStyle.msTransitionDuration = elStyle.MozTransitionDuration = elStyle.OTransitionDuration = elStyle.transitionDuration = duration;
    }
    return this;
};
$.fn.transitionEnd = function (callback) {
    var events = ['webkitTransitionEnd', 'transitionend', 'oTransitionEnd', 'MSTransitionEnd', 'msTransitionEnd'],
        i, j, dom = this;

    function fireCallBack(e) {
        /*jshint validthis:true */
        if (e.target !== this) return;
        callback.call(this, e);
        for (i = 0; i < events.length; i++) {
            dom.off(events[i], fireCallBack);
        }
    }

    if (callback) {
        for (i = 0; i < events.length; i++) {
            dom.on(events[i], fireCallBack);
        }
    }
    return this;
};
$.fn.animationEnd = function (callback) {
    var events = ['webkitAnimationEnd', 'OAnimationEnd', 'MSAnimationEnd', 'animationend'],
        i, j, dom = this;

    function fireCallBack(e) {
        callback(e);
        for (i = 0; i < events.length; i++) {
            dom.off(events[i], fireCallBack);
        }
    }

    if (callback) {
        for (i = 0; i < events.length; i++) {
            dom.on(events[i], fireCallBack);
        }
    }
    return this;
};
