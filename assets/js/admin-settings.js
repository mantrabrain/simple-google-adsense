/**
 * Simple Google AdSense Admin Settings JavaScript
 *
 * @package Simple_Google_Adsense
 * @since   1.2.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        var $settingsForm = $('#adsense-settings-form');

        // Copy shortcode to clipboard
        $('.adsense-doc-section code').on('click', function() {
            var $code = $(this);
            var originalText = $code.text();

            var showCopied = function() {
                $code.text('Copied!').addClass('copied');

                setTimeout(function() {
                    $code.text(originalText).removeClass('copied');
                }, 2000);
            };

            // navigator.clipboard is only available in a secure context.
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(originalText).then(showCopied);
                return;
            }

            var $helper = $('<textarea>').val(originalText).css({
                position: 'fixed',
                top: '-1000px'
            }).appendTo('body');

            $helper.get(0).select();

            try {
                document.execCommand('copy');
                showCopied();
            } catch (e) {
                // Copying is a convenience only - leave the code visible to select by hand.
            }

            $helper.remove();
        });

        // Add copy affordance to code blocks
        $('.adsense-doc-section code').each(function() {
            $(this).attr('title', 'Click to copy');
            $(this).css('cursor', 'pointer');
        });

        // Smooth scroll to sections
        $('.adsense-documentation-sidebar a[href^="#"]').on('click', function(e) {
            e.preventDefault();
            var target = $(this.getAttribute('href'));
            if (target.length) {
                $('html, body').animate({
                    scrollTop: target.offset().top - 100
                }, 500);
            }
        });

        // Highlight current section
        function highlightCurrentSection() {
            var scrollTop = $(window).scrollTop();
            var windowHeight = $(window).height();

            $('.adsense-doc-section').each(function() {
                var $section = $(this);
                var sectionTop = $section.offset().top;
                var sectionHeight = $section.outerHeight();

                if (scrollTop + windowHeight > sectionTop && scrollTop < sectionTop + sectionHeight) {
                    $section.addClass('active');
                } else {
                    $section.removeClass('active');
                }
            });
        }

        // Throttle scroll events
        var scrollTimeout;
        $(window).on('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(highlightCurrentSection, 100);
        });

        // Warn - but do not block - when saving without a Publisher ID, so the
        // field can still be cleared deliberately.
        $settingsForm.on('submit', function() {
            var publisherId = $('input[name="simple_google_adsense_settings[publisher_id]"]').val() || '';

            if (!publisherId.trim() && !window.confirm('No Google AdSense Publisher ID is set. Save anyway?')) {
                return false;
            }

            $('.adsense-settings-main').addClass('loading');

            return true;
        });

        // Settings help tooltips
        $('.form-table th').each(function() {
            var $th = $(this);
            var fieldName = $th.text().toLowerCase();

            if (fieldName.indexOf('publisher id') !== -1) {
                $th.append('<span class="dashicons dashicons-editor-help help-icon" data-tooltip="Your Google AdSense Publisher ID (e.g., pub-1234567890123456)"></span>');
            } else if (fieldName.indexOf('auto ads') !== -1) {
                $th.append('<span class="dashicons dashicons-editor-help help-icon" data-tooltip="Google\'s AI automatically places ads where they perform best"></span>');
            } else if (fieldName.indexOf('manual ads') !== -1) {
                $th.append('<span class="dashicons dashicons-editor-help help-icon" data-tooltip="Place ads manually using shortcodes and Gutenberg blocks"></span>');
            }
        });

        // Responsive sidebar toggle
        if ($(window).width() <= 1400) {
            $('.adsense-settings-sidebar').prepend('<button type="button" class="adsense-sidebar-toggle">📚 Show/Hide Documentation</button>');

            $('.adsense-sidebar-toggle').on('click', function() {
                $('.adsense-documentation-sidebar').toggle();
            });
        }

        // Search functionality for documentation
        $('.adsense-documentation-sidebar').prepend('<input type="text" class="adsense-doc-search" placeholder="Search documentation...">');

        $('.adsense-doc-search').on('input', function() {
            var searchTerm = $(this).val().toLowerCase();

            $('.adsense-doc-section').each(function() {
                var $section = $(this);
                var sectionText = $section.text().toLowerCase();

                if (sectionText.indexOf(searchTerm) !== -1) {
                    $section.show();
                } else {
                    $section.hide();
                }
            });
        });

        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                $settingsForm.trigger('submit');
            }

            // Ctrl/Cmd + / to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                $('.adsense-doc-search').trigger('focus');
            }
        });

        // Custom tooltip functionality
        $(document).on('mouseenter', '.help-icon', function() {
            var tooltip = $(this).data('tooltip');
            var $this = $(this);

            // Remove existing tooltips
            $('.custom-tooltip').remove();

            // Create tooltip
            var $tooltip = $('<div class="custom-tooltip"></div>').text(tooltip);
            $('body').append($tooltip);

            // Position tooltip
            var offset = $this.offset();
            var tooltipWidth = $tooltip.outerWidth();
            var tooltipHeight = $tooltip.outerHeight();

            var left = offset.left + $this.outerWidth() + 10;
            var top = offset.top - (tooltipHeight / 2) + ($this.outerHeight() / 2);

            // Adjust if tooltip goes off screen
            if (left + tooltipWidth > $(window).width()) {
                left = offset.left - tooltipWidth - 10;
            }

            if (top < 0) {
                top = 10;
            } else if (top + tooltipHeight > $(window).height()) {
                top = $(window).height() - tooltipHeight - 10;
            }

            $tooltip.css({
                left: left + 'px',
                top: top + 'px'
            });

            $tooltip.fadeIn(200);
        });

        $(document).on('mouseleave', '.help-icon', function() {
            $('.custom-tooltip').fadeOut(200, function() {
                $(this).remove();
            });
        });

        // Remove tooltips when clicking elsewhere
        $(document).on('click', function(e) {
            if (!$(e.target).hasClass('help-icon')) {
                $('.custom-tooltip').remove();
            }
        });

    });

})(jQuery);
