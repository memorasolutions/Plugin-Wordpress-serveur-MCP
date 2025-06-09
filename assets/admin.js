/**
 * Fichier: /wp-content/plugins/wordpress-mcp-full-access/assets/admin.js
 * Scripts pour l'interface d'administration MCP
 * Développé par MEMORA - https://memora.solutions
 */

jQuery(document).ready(function($) {
    
    // Fonction globale pour copier dans le presse-papier
    window.copyToClipboard = function(text) {
        // Méthode moderne avec l'API Clipboard si disponible
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showCopyNotification('Copié dans le presse-papier !');
            }).catch(function() {
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    };
    
    // Méthode de fallback pour copier
    function fallbackCopyToClipboard(text) {
        var temp = document.createElement('textarea');
        temp.value = text;
        temp.style.position = 'fixed';
        temp.style.left = '-999999px';
        document.body.appendChild(temp);
        temp.select();
        temp.setSelectionRange(0, 99999); // Pour mobile
        
        try {
            document.execCommand('copy');
            showCopyNotification('Copié dans le presse-papier !');
        } catch (err) {
            showCopyNotification('Erreur lors de la copie', 'error');
        }
        
        document.body.removeChild(temp);
    }
    
    // Afficher une notification de copie
    function showCopyNotification(message, type = 'success') {
        var $notification = $('<div class="copy-notification ' + type + '">' + message + '</div>');
        
        $notification.css({
            position: 'fixed',
            top: '50px',
            right: '20px',
            background: type === 'success' ? '#46b450' : '#dc3232',
            color: 'white',
            padding: '10px 20px',
            borderRadius: '4px',
            boxShadow: '0 2px 5px rgba(0,0,0,0.2)',
            zIndex: 99999,
            fontSize: '14px',
            fontWeight: 'bold',
            opacity: 0,
            transform: 'translateY(-10px)'
        });
        
        $('body').append($notification);
        
        $notification.animate({
            opacity: 1,
            transform: 'translateY(0)'
        }, 200);
        
        setTimeout(function() {
            $notification.animate({
                opacity: 0,
                transform: 'translateY(-10px)'
            }, 200, function() {
                $notification.remove();
            });
        }, 2000);
    }
    
    // Ajouter des boutons de copie pour toutes les URLs
    $('code').each(function() {
        var $code = $(this);
        var text = $code.text();
        
        // Si c'est une URL ou un code important
        if (text.startsWith('http') || text.startsWith('mcp_') || text.startsWith('ref_') || text.length > 20) {
            // Rendre le code sélectionnable facilement
            $code.css('cursor', 'pointer').attr('title', 'Cliquez pour sélectionner');
            
            $code.click(function() {
                var range = document.createRange();
                range.selectNodeContents(this);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            });
        }
    });
    
    // Afficher/Masquer les secrets OAuth avec animation
    $('.show-secret').click(function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $secret = $btn.prev('.client-secret');
        var $copyBtn = $btn.next('button');
        var secret = $secret.data('secret');
        
        if ($secret.text().includes('•')) {
            // Afficher le secret
            $secret.fadeOut(100, function() {
                $secret.text(secret).fadeIn(100);
            });
            $btn.text('Masquer');
            if ($copyBtn.length) {
                $copyBtn.show();
            }
        } else {
            // Masquer le secret
            $secret.fadeOut(100, function() {
                $secret.text('••••••••••••••••').fadeIn(100);
            });
            $btn.text('Afficher');
            if ($copyBtn.length) {
                $copyBtn.hide();
            }
        }
    });
    
    // Confirmation de suppression améliorée
    $('a[href*="delete"]').click(function(e) {
        var itemName = $(this).closest('tr').find('td:first').text().trim();
        var message = 'Êtes-vous sûr de vouloir supprimer ce client OAuth ?\n\n';
        message += 'Client : ' + itemName + '\n\n';
        message += 'Cette action est irréversible.';
        
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });
    
    // Accordion pour les capacités (clic sur les titres)
    $('.mcp-capabilities h3').css('cursor', 'pointer').click(function() {
        var $list = $(this).next('ul');
        var $icon = $(this).find('.toggle-icon');
        
        if (!$icon.length) {
            $icon = $('<span class="toggle-icon" style="float: right;">▼</span>');
            $(this).append($icon);
        }
        
        $list.slideToggle(300);
        $(this).toggleClass('collapsed');
        
        if ($(this).hasClass('collapsed')) {
            $icon.text('▶');
        } else {
            $icon.text('▼');
        }
    });
    
    // Recherche dans les logs
    if ($('.wp-list-table').length && $('body').hasClass('mcp-server_page_mcp-logs')) {
        var $searchBox = $('<div class="tablenav top"><input type="text" id="mcp-log-search" placeholder="Rechercher dans les logs..." style="margin: 10px 0; padding: 5px 10px; width: 300px; font-size: 14px;"></div>');
        $('.wp-list-table').before($searchBox);
        
        $('#mcp-log-search').on('keyup', function() {
            var searchTerm = $(this).val().toLowerCase();
            var visibleCount = 0;
            
            $('.wp-list-table tbody tr').each(function() {
                var $row = $(this);
                var text = $row.text().toLowerCase();
                
                if (searchTerm === '' || text.indexOf(searchTerm) > -1) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }
            });
            
            // Afficher le nombre de résultats
            $('#search-results-count').remove();
            if (searchTerm) {
                $searchBox.append('<span id="search-results-count" style="margin-left: 10px; color: #666;">' + visibleCount + ' résultat(s)</span>');
            }
        });
    }
    
    // Amélioration de l'affichage JSON dans les logs
    $('details pre').each(function() {
        var $pre = $(this);
        var content = $pre.text();
        
        try {
            // Parser et reformater le JSON
            var parsed = JSON.parse(content);
            var formatted = JSON.stringify(parsed, null, 2);
            
            // Coloration syntaxique basique
            formatted = formatted
                .replace(/("[\w\s]+":)/g, '<span style="color: #667eea;">$1</span>')
                .replace(/(:\s*"[^"]*")/g, '<span style="color: #46b450;">$1</span>')
                .replace(/(:\s*\d+)/g, '<span style="color: #dc3232;">$1</span>')
                .replace(/(true|false|null)/g, '<span style="color: #f39c12;">$1</span>');
            
            $pre.html(formatted);
        } catch(e) {
            // Si ce n'est pas du JSON valide, laisser tel quel
        }
    });
    
    // Export des logs en CSV
    if ($('body').hasClass('mcp-server_page_mcp-logs')) {
        var $exportBtn = $('<button id="export-logs" class="button" style="margin: 10px 0;">📥 Exporter les logs (CSV)</button>');
        $('.wrap h1').after($exportBtn);
        
        $('#export-logs').click(function(e) {
            e.preventDefault();
            
            var csv = 'Date,Utilisateur,Outil,Arguments,IP\n';
            
            $('.wp-list-table tbody tr:visible').each(function() {
                var $cells = $(this).find('td');
                var row = [];
                
                $cells.each(function(index) {
                    if (index < 5) {
                        var text = $(this).text().trim();
                        // Nettoyer le texte pour CSV
                        if (index === 3) { // Arguments
                            text = $(this).find('summary').text() || 'Voir détails';
                        }
                        row.push('"' + text.replace(/"/g, '""') + '"');
                    }
                });
                
                csv += row.join(',') + '\n';
            });
            
            // Télécharger le CSV
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'mcp-logs-' + new Date().toISOString().split('T')[0] + '.csv';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showCopyNotification('Logs exportés !');
        });
    }
    
    // Test de connexion OAuth
    $('.button[href*="config"]').click(function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var url = $btn.attr('href') || $btn.prev('code').text();
        
        $btn.text('Test en cours...').prop('disabled', true);
        
        $.ajax({
            url: url,
            method: 'GET',
            timeout: 5000,
            success: function(data) {
                $btn.text('✅ Connexion OK').css('color', '#46b450');
                console.log('Configuration MCP:', data);
                
                // Afficher les infos dans une popup
                var info = 'Configuration MCP valide !\n\n';
                info += 'Issuer: ' + data.issuer + '\n';
                info += 'Grant types: ' + data.grant_types_supported.join(', ');
                alert(info);
            },
            error: function(xhr) {
                $btn.text('❌ Erreur').css('color', '#dc3232');
                alert('Erreur de connexion : ' + xhr.status + ' ' + xhr.s/**
 * Fichier: /wp-content/plugins/wordpress-mcp-full-access/assets/admin.js
 * Scripts pour l'interface d'administration MCP
 */

jQuery(document).ready(function($) {
    
    // Ajouter des boutons de copie pour les URLs
    $('code').each(function() {
        var $code = $(this);
        var text = $code.text();
        
        // Si c'est une URL
        if (text.startsWith('http')) {
            var $copyBtn = $('<button class="button-link copy-btn" style="margin-left: 10px;">📋 Copier</button>');
            
            $copyBtn.click(function(e) {
                e.preventDefault();
                
                // Copier dans le presse-papier
                var tempInput = $('<input>');
                $('body').append(tempInput);
                tempInput.val(text).select();
                document.execCommand('copy');
                tempInput.remove();
                
                // Feedback visuel
                var $btn = $(this);
                var originalText = $btn.text();
                $btn.text('✅ Copié!').css('color', '#46b450');
                
                setTimeout(function() {
                    $btn.text(originalText).css('color', '');
                }, 2000);
            });
            
            $code.after($copyBtn);
        }
    });
    
    // Afficher/Masquer les secrets OAuth
    $('.show-secret').click(function(e) {
        e.preventDefault();
        
        var $secret = $(this).prev('.client-secret');
        var secret = $secret.data('secret');
        
        if ($secret.text().includes('•')) {
            $secret.text(secret);
            $(this).text('Masquer');
        } else {
            $secret.text('••••••••••••••••');
            $(this).text('Afficher');
        }
    });
    
    // Confirmation de suppression
    $('a[href*="delete"]').click(function(e) {
        if (!confirm('Êtes-vous sûr de vouloir supprimer cet élément ?')) {
            e.preventDefault();
        }
    });
    
    // Génération automatique de client secret
    $('#regenerate-secret').click(function(e) {
        e.preventDefault();
        
        // Générer un secret aléatoire
        var secret = generateRandomString(64);
        $('#client_secret').val(secret);
        
        // Animation
        $('#client_secret').css('background-color', '#ffffcc')
            .animate({ backgroundColor: '#ffffff' }, 1000);
    });
    
    // Fonction pour générer une chaîne aléatoire
    function generateRandomString(length) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var result = '';
        for (var i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }
    
    // Accordion pour les capacités
    $('.mcp-capabilities h3').click(function() {
        $(this).next('ul').slideToggle(300);
        $(this).toggleClass('collapsed');
    });
    
    // Recherche dans les logs
    if ($('.wp-list-table').length) {
        var $searchBox = $('<input type="text" placeholder="Rechercher dans les logs..." style="margin: 10px 0; padding: 5px; width: 300px;">');
        $('.wp-list-table').before($searchBox);
        
        $searchBox.on('keyup', function() {
            var searchTerm = $(this).val().toLowerCase();
            
            $('.wp-list-table tbody tr').each(function() {
                var $row = $(this);
                var text = $row.text().toLowerCase();
                
                if (text.indexOf(searchTerm) > -1) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        });
    }
    
    // Affichage des détails JSON avec coloration syntaxique
    $('details pre').each(function() {
        var $pre = $(this);
        var json = $pre.text();
        
        try {
            // Parser et reformater le JSON
            var parsed = JSON.parse(json);
            var formatted = JSON.stringify(parsed, null, 2);
            
            // Appliquer une coloration basique
            formatted = formatted
                .replace(/("[\w\s]+":)/g, '<span style="color: #667eea;">$1</span>')
                .replace(/(:\s*"[^"]*")/g, '<span style="color: #46b450;">$1</span>')
                .replace(/(\d+)/g, '<span style="color: #dc3232;">$1</span>');
            
            $pre.html(formatted);
        } catch(e) {
            // Si ce n'est pas du JSON valide, laisser tel quel
        }
    });
    
    // Tooltip pour les informations importantes
    $('[data-tooltip]').each(function() {
        var $elem = $(this);
        var tooltip = $elem.data('tooltip');
        
        $elem.hover(
            function() {
                $('<div class="mcp-tooltip">' + tooltip + '</div>')
                    .appendTo('body')
                    .css({
                        position: 'absolute',
                        background: '#333',
                        color: '#fff',
                        padding: '5px 10px',
                        borderRadius: '4px',
                        fontSize: '12px',
                        zIndex: 1000,
                        top: $elem.offset().top - 30,
                        left: $elem.offset().left
                    })
                    .fadeIn(200);
            },
            function() {
                $('.mcp-tooltip').remove();
            }
        );
    });
    
    // Animation de succès après création d'un client
    if (window.location.href.includes('client-created=true')) {
        $('.notice-success').hide().slideDown(500);
    }
    
    // Export des logs
    $('#export-logs').click(function(e) {
        e.preventDefault();
        
        var csv = 'Date,Utilisateur,Outil,Arguments,IP\n';
        
        $('.wp-list-table tbody tr').each(function() {
            var $cells = $(this).find('td');
            var row = [];
            
            $cells.each(function(index) {
                if (index < 5) { // Exclure la colonne détails
                    row.push('"' + $(this).text().trim().replace(/"/g, '""') + '"');
                }
            });
            
            csv += row.join(',') + '\n';
        });
        
        // Télécharger le CSV
        var blob = new Blob([csv], { type: 'text/csv' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'mcp-logs-' + new Date().toISOString().split('T')[0] + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    });
    
    // Test de connexion OAuth
    $('#test-oauth').click(function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        $btn.text('Test en cours...').prop('disabled', true);
        
        // Simuler un test (à remplacer par un vrai test AJAX)
        setTimeout(function() {
            $btn.text('✅ Connexion réussie!').css('color', '#46b450');
            
            setTimeout(function() {
                $btn.text('Tester la connexion').css('color', '').prop('disabled', false);
            }, 3000);
        }, 2000);
    });
    
});