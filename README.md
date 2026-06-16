# Widget Conditional Tags

Zeigt klassische WordPress-Widgets abhängig von Conditional Tags an oder blendet sie aus. Als schlanker, sicherer Nachfolger-Gedanke zu **Widget Logic**.
 
## Warum noch ein Widget-Bedingungs-Plugin?
 
Widget Logic war jahrelang das Standard-Werkzeug dafür. Es wird nicht mehr gepflegt, und sein eigentliches Problem war hausgemacht: Die eingegebene Bedingung wurde per `eval()` als PHP ausgeführt. Das ist genau die Mechanik, die in mehreren vergleichbaren Plugins zu authentifizierten Remote-Code-Execution-Lücken geführt hat.
 
Dieses Plugin geht den umgekehrten Weg. Es führt **keinen** PHP-Code aus. Stattdessen liest ein kleiner Tokenizer und ein Recursive-Descent-Parser eine bewusst eng gehaltene Ausdruckssprache und ruft ausschließlich Funktionen aus einer Whitelist auf. Damit fällt die ganze Klasse von eval-Lücken strukturell weg – nicht durch nachträgliche Filter, sondern durch das Design.
 
Bewusste Reduktion gegenüber Widget Logic: weniger Freiheit, dafür keine Angriffsfläche. Für die allermeisten realen Fälle reicht das.
 
## Funktionen
 
- Bedingungsfeld unter jedem klassischen Widget
- Conditional Tags positiv oder negativ: `is_home`, `!is_search()`
- Verknüpfung mit `AND` / `&&` und `OR` / `||`, Klammern für Gruppierung
- Operator-Vorrang: `NOT` > `AND` > `OR`
- Validierung schon beim Speichern, mit verständlicher Fehlermeldung
- Erweiterbar per Filter für eigene parameterlose Conditional Tags
- Vollständig übersetzbar (Text Domain `widget-conditional-tags`)

## Beispiele
 
```php
is_home() OR is_archive()
is_paged() OR is_archive()
(is_single() OR is_page()) AND !is_paged()
!is_search()
```
 
Leeres Feld = Widget wird überall angezeigt.
 
## Erlaubte Conditional Tags
 
`is_404`, `is_archive`, `is_attachment`, `is_author`, `is_category`, `is_date`, `is_day`, `is_feed`, `is_front_page`, `is_home`, `is_month`, `is_page`, `is_paged`, `is_post_type_archive`, `is_privacy_policy`, `is_search`, `is_single`, `is_singular`, `is_sticky`, `is_tag`, `is_tax`, `is_time`, `is_user_logged_in`, `is_year`.
 
## Bewusst (noch) nicht enthalten
 
- **Parameter** wie `is_page( 42 )` oder `is_category( 'news' )`. Nur leere Klammern werden toleriert. Parameter würden den Parser deutlich vergrößern und müssten garantieren, dass wirklich nur Literale durchgehen.
- **Block-Widget-Editor.** Das Plugin setzt auf die klassische Widget-Oberfläche und die Hooks `in_widget_form`, `widget_update_callback` und `widget_display_callback`. Im Block-Editor greift die Anzeige-Logik nicht.
## Voraussetzungen
 
- WordPress mit klassischer Widget-Oberfläche, z. B. über das Plugin „Classic Widgets"
- WordPress 6.5 oder neuer, PHP 7.4 oder neuer
## Installation
 
1. Den Ordner `widget-conditional-tags` nach `/wp-content/plugins/` kopieren (oder das ZIP über *Plugins → Installieren → Plugin hochladen* einspielen).
2. Plugin aktivieren.
3. Klassische Widgets-Seite öffnen.
4. Unter einem Widget die Bedingung eintragen.
## Für Entwickler: eigene Bedingungen ergänzen
 
```php
add_filter( 'wct_allowed_conditions', static function ( $conditions ) {
    // Muss ohne Parameter aufrufbar sein und boolean-artig zurückgeben.
    $conditions['is_woocommerce'] = 'is_woocommerce';
    return $conditions;
} );
```
 
## Sicherheit
 
- Kein `eval()`, kein `call_user_func` auf ungeprüfte Strings, kein `$$variable`.
- Jeder Tag wird gegen die Whitelist geprüft und zusätzlich per `is_callable()` abgesichert.
- Capability-Prüfung (`edit_theme_options`) beim Speichern; der Standard-Widget-Speicherpfad bringt den Nonce-Schutz mit.
- Eingaben werden beim Speichern bereinigt, alle Ausgaben im Backend escaped.

## Lizenz
 
GPL-2.0-or-later.
 
## Autor
 
Vladimir Simović — [perun.net](https://www.perun.net)
