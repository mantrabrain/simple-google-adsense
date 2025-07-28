/**
 * Simple Google AdSense Admin Settings JavaScript
 *
 * @package Simple_Google_Adsense
 * @since   1.2.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Copy shortcode to clipboard
        $('.adsense-doc-section code').on('click', function() {
            const text = $(this).text();
            navigator.clipboard.writeText(text).then(function() {
                // Show success message
                const $code = $(this);
                const originalText = $code.text();
                $code.text('Copied!').addClass('copied');
                
                setTimeout(function() {
                    $code.text(originalText).removeClass('copied');
                }, 2000);
            }.bind(this));
        });

        // Add copy button to code blocks
        $('.adsense-doc-section code').each(function() {
            $(this).attr('title', 'Click to copy');
            $(this).css('cursor', 'pointer');
        });

        // Smooth scroll to sections
        $('.adsense-documentation-sidebar a[href^="#"]').on('click', function(e) {
            e.preventDefault();
            const target = $(this.getAttribute('href'));
            if (target.length) {
                $('html, body').animate({
                    scrollTop: target.offset().top - 100
                }, 500);
            }
        });

        // Highlight current section
        function highlightCurrentSection() {
            const scrollTop = $(window).scrollTop();
            const windowHeight = $(window).height();
            
            $('.adsense-doc-section').each(function() {
                const $section = $(this);
                const sectionTop = $section.offset().top;
                const sectionHeight = $section.outerHeight();
                
                if (scrollTop + windowHeight > sectionTop && scrollTop < sectionTop + sectionHeight) {
                    $section.addClass('active');
                } else {
                    $section.removeClass('active');
                }
            });
        }

        // Throttle scroll events
        let scrollTimeout;
        $(window).on('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(highlightCurrentSection, 100);
        });

        // Form validation
        $('form').on('submit', function(e) {
            const publisherId = $('input[name="simple_google_adsense_settings[publisher_id]"]').val();
            
            if (!publisherId.trim()) {
                e.preventDefault();
                alert('Please enter your Google AdSense Publisher ID.');
                return false;
            }
            
            // Show loading state
            $('.adsense-settings-main').addClass('loading');
        });

        // Settings help tooltips
        $('.form-table th').each(function() {
            const $th = $(this);
            const fieldName = $th.text().toLowerCase();
            
            if (fieldName.includes('publisher id')) {
                $th.append('<span class="dashicons dashicons-editor-help help-icon" data-tooltip="Your Google AdSense Publisher ID (e.g., pub-1234567890123456)"></span>');
            } else if (fieldName.includes('auto ads')) {
                $th.append('<span class="dashicons dashicons-editor-help help-icon" data-tooltip="Google\'s AI automatically places ads where they perform best"></span>');
            } else if (fieldName.includes('manual ads')) {
                $th.append('<span class="dashicons dashicons-editor-help help-icon" data-tooltip="Place ads manually using shortcodes and Gutenberg blocks"></span>');
            }
        });

        // Responsive sidebar toggle
        if ($(window).width() <= 1400) {
            $('.adsense-settings-sidebar').prepend('<button class="adsense-sidebar-toggle">📚 Show/Hide Documentation</button>');
            
            $('.adsense-sidebar-toggle').on('click', function() {
                $('.adsense-documentation-sidebar').toggle();
            });
        }

        // Search functionality for documentation
        $('.adsense-documentation-sidebar').prepend('<input type="text" class="adsense-doc-search" placeholder="Search documentation...">');
        
        $('.adsense-doc-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            
            $('.adsense-doc-section').each(function() {
                const $section = $(this);
                const sectionText = $section.text().toLowerCase();
                
                if (sectionText.includes(searchTerm)) {
                    $section.show();
                } else {
                    $section.hide();
                }
            });
        });

        // Auto-save settings (optional)
        let saveTimeout;
        $('input, select, textarea').on('change', function() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                // Show auto-save indicator
                $('.submit').append('<span class="auto-save-indicator">Auto-saving...</span>');
                
                setTimeout(function() {
                    $('.auto-save-indicator').remove();
                }, 2000);
            }, 2000);
        });

        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                $('form').submit();
            }
            
            // Ctrl/Cmd + / to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                $('.adsense-doc-search').focus();
            }
        });

        // Custom tooltip functionality
        $('.help-icon').on('mouseenter', function() {
            const tooltip = $(this).data('tooltip');
            const $this = $(this);
            
            // Remove existing tooltips
            $('.custom-tooltip').remove();
            
            // Create tooltip
            const $tooltip = $('<div class="custom-tooltip">' + tooltip + '</div>');
            $('body').append($tooltip);
            
            // Position tooltip
            const offset = $this.offset();
            const tooltipWidth = $tooltip.outerWidth();
            const tooltipHeight = $tooltip.outerHeight();
            
            let left = offset.left + $this.outerWidth() + 10;
            let top = offset.top - (tooltipHeight / 2) + ($this.outerHeight() / 2);
            
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
        
        $('.help-icon').on('mouseleave', function() {
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