<?php
/**
 * Plugin Name:       Widget Conditional Tags
 * Plugin URI:        https://www.perun.net/
 * Description:       Zeigt klassische WordPress-Widgets abhängig von sicheren, whitelisted Conditional Tags an oder blendet sie aus – bewusst ohne eval(). Nachfolger-Idee zu „Widget Logic".
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Vladimir Simović
 * Author URI:        https://www.perun.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       widget-conditional-tags
 * Domain Path:       /languages
 *
 * Voraussetzung: klassische Widget-Oberfläche (z. B. über das Plugin „Classic Widgets").
 * Im Block-Widget-Editor greift widget_display_callback nicht.
 *
 * @package Widget_Conditional_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCT_VERSION', '1.2.0' );

/**
 * Sicherer Parser und Auswerter für boolesche Ausdrücke über Conditional Tags.
 *
 * Operator-Vorrang: NOT > AND > OR.
 * Erlaubt z. B.: is_home, is_home(), !is_search(), is_home OR is_archive,
 *                (is_single() OR is_page()) AND !is_paged().
 * AND = "AND" oder "&&", OR = "OR" oder "||" (Groß-/Kleinschreibung egal).
 *
 * Kein eval(): Jeder Tag wird gegen eine Whitelist geprüft und nur dann als
 * parameterlose Funktion aufgerufen. Parameter (z. B. is_page( 42 )) werden
 * in dieser Version bewusst nicht unterstützt – das hält die Angriffsfläche null.
 */
final class WCT_Condition_Parser {

	const T_COND   = 'cond';
	const T_AND    = 'and';
	const T_OR     = 'or';
	const T_NOT    = 'not';
	const T_LPAREN = 'lparen';
	const T_RPAREN = 'rparen';
	const T_EOF    = 'eof';

	/**
	 * Tokens des aktuellen Durchlaufs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $tokens = array();

	/**
	 * Aktuelle Token-Position.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Whitelist des aktuellen Durchlaufs (einmal pro parse() geladen).
	 *
	 * @var array<string, callable|string>
	 */
	private $allowed = array();

	/**
	 * Liefert die Whitelist erlaubter, parameterloser Conditional Tags.
	 *
	 * Schlüssel sind die Namen, die Nutzer eingeben dürfen; Werte sind die
	 * aufzurufenden Callbacks.
	 *
	 * @return array<string, callable|string>
	 */
	public static function allowed_conditions() {
		$conditions = array(
			'is_404'               => 'is_404',
			'is_archive'           => 'is_archive',
			'is_attachment'        => 'is_attachment',
			'is_author'            => 'is_author',
			'is_category'          => 'is_category',
			'is_date'              => 'is_date',
			'is_day'               => 'is_day',
			'is_feed'              => 'is_feed',
			'is_front_page'        => 'is_front_page',
			'is_home'              => 'is_home',
			'is_month'             => 'is_month',
			'is_page'              => 'is_page',
			'is_paged'             => 'is_paged',
			'is_post_type_archive' => 'is_post_type_archive',
			'is_privacy_policy'    => 'is_privacy_policy',
			'is_search'            => 'is_search',
			'is_single'            => 'is_single',
			'is_singular'          => 'is_singular',
			'is_sticky'            => 'is_sticky',
			'is_tag'               => 'is_tag',
			'is_tax'               => 'is_tax',
			'is_time'              => 'is_time',
			'is_user_logged_in'    => 'is_user_logged_in',
			'is_year'              => 'is_year',
		);

		/**
		 * Filtert die erlaubten Conditional-Tag-Callbacks.
		 *
		 * Der Callback muss ohne Parameter aufrufbar sein und einen
		 * boolean-artigen Wert zurückgeben.
		 *
		 * Beispiel:
		 * add_filter( 'wct_allowed_conditions', static function ( $conditions ) {
		 *     $conditions['is_woocommerce'] = 'is_woocommerce';
		 *     return $conditions;
		 * } );
		 *
		 * @param array<string, callable|string> $conditions Erlaubte Callbacks.
		 */
		$conditions = apply_filters( 'wct_allowed_conditions', $conditions );

		return self::normalize( $conditions );
	}

	/**
	 * Normalisiert die Filter-Ausgabe und entfernt ungültige Namen.
	 *
	 * @param mixed $conditions Rohwert.
	 * @return array<string, callable|string>
	 */
	private static function normalize( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return array();
		}

		$out = array();

		foreach ( $conditions as $name => $callback ) {
			// Erlaubt auch einfache Listen: array( 'is_home', 'is_archive' ).
			if ( is_int( $name ) && is_string( $callback ) ) {
				$name = $callback;
			}

			if ( ! is_string( $name ) ) {
				continue;
			}

			$name = trim( $name );

			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
				continue;
			}

			// Nur tatsächlich aufrufbare Callbacks übernehmen. Ein per Filter
			// ergänzter, aber nicht (mehr) vorhandener Tag würde sonst in einer
			// Negation wie !is_woocommerce() zu !false = true kippen und das
			// Widget unerwartet einblenden. Beim Speichern und im Frontend ist
			// init bereits durchlaufen, alle aktiven Plugins sind geladen –
			// is_callable() ist hier also verlässlich.
			if ( ! is_callable( $callback ) ) {
				continue;
			}

			$out[ $name ] = $callback;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Wertet einen Ausdruck aus und gibt true/false zurück.
	 *
	 * Ein leerer Ausdruck ergibt true (Widget überall anzeigen).
	 *
	 * @param string $expression Roher Ausdruck.
	 * @return bool
	 * @throws InvalidArgumentException Bei ungültigem Ausdruck.
	 */
	public function evaluate( $expression ) {
		return $this->run_ast( $this->parse( $expression ) );
	}

	/**
	 * Parst einen Ausdruck in einen AST. Auch zur reinen Validierung nutzbar.
	 *
	 * @param string $expression Roher Ausdruck.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException Bei ungültigem Ausdruck.
	 */
	public function parse( $expression ) {
		$expression = trim( (string) $expression );

		if ( '' === $expression ) {
			return array( 'type' => 'empty' );
		}

		$this->allowed = self::allowed_conditions();
		$this->tokens  = $this->tokenize( $expression );
		$this->pos     = 0;

		$ast = $this->parse_or();

		if ( self::T_EOF !== $this->type() ) {
			$token = $this->token();
			throw new InvalidArgumentException(
				sprintf(
					'Unerwarteter Ausdruck „%s" an Position %d.',
					isset( $token['value'] ) ? $token['value'] : $token['type'],
					(int) $token['pos']
				)
			);
		}

		return $ast;
	}

	/**
	 * Zerlegt den Ausdruck in Tokens.
	 *
	 * @param string $expr Roher Ausdruck.
	 * @return array<int, array<string, mixed>>
	 * @throws InvalidArgumentException Bei unerlaubten Zeichen oder Parametern.
	 */
	private function tokenize( $expr ) {
		$tokens = array();
		$len    = strlen( $expr );
		$i      = 0;

		while ( $i < $len ) {
			$c = $expr[ $i ];

			if ( preg_match( '/\s/', $c ) ) {
				++$i;
				continue;
			}

			if ( '!' === $c ) {
				$tokens[] = array(
					'type' => self::T_NOT,
					'pos'  => $i,
				);
				++$i;
				continue;
			}

			if ( '&' === $c && isset( $expr[ $i + 1 ] ) && '&' === $expr[ $i + 1 ] ) {
				$tokens[] = array(
					'type' => self::T_AND,
					'pos'  => $i,
				);
				$i += 2;
				continue;
			}

			if ( '|' === $c && isset( $expr[ $i + 1 ] ) && '|' === $expr[ $i + 1 ] ) {
				$tokens[] = array(
					'type' => self::T_OR,
					'pos'  => $i,
				);
				$i += 2;
				continue;
			}

			if ( '(' === $c ) {
				$tokens[] = array(
					'type' => self::T_LPAREN,
					'pos'  => $i,
				);
				++$i;
				continue;
			}

			if ( ')' === $c ) {
				$tokens[] = array(
					'type' => self::T_RPAREN,
					'pos'  => $i,
				);
				++$i;
				continue;
			}

			if ( preg_match( '/[A-Za-z_]/', $c ) ) {
				$start = $i;
				++$i;

				while ( $i < $len && preg_match( '/[A-Za-z0-9_]/', $expr[ $i ] ) ) {
					++$i;
				}

				$name = substr( $expr, $start, $i - $start );
				$up   = strtoupper( $name );

				if ( 'AND' === $up ) {
					$tokens[] = array(
						'type' => self::T_AND,
						'pos'  => $start,
					);
					continue;
				}

				if ( 'OR' === $up ) {
					$tokens[] = array(
						'type' => self::T_OR,
						'pos'  => $start,
					);
					continue;
				}

				// Leere Klammern tolerieren ( is_home() ), echte Parameter ablehnen.
				$j = $i;
				while ( $j < $len && preg_match( '/\s/', $expr[ $j ] ) ) {
					++$j;
				}

				if ( $j < $len && '(' === $expr[ $j ] ) {
					$k = $j + 1;
					while ( $k < $len && preg_match( '/\s/', $expr[ $k ] ) ) {
						++$k;
					}

					if ( $k >= $len || ')' !== $expr[ $k ] ) {
						throw new InvalidArgumentException(
							sprintf(
								'Parameter werden in dieser Version nicht unterstützt: „%s(...)" an Position %d.',
								$name,
								$start
							)
						);
					}

					$i = $k + 1;
				}

				if ( ! isset( $this->allowed[ $name ] ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Unbekannter oder nicht erlaubter Conditional Tag: „%s".', $name )
					);
				}

				$tokens[] = array(
					'type'  => self::T_COND,
					'value' => $name,
					'pos'   => $start,
				);
				continue;
			}

			throw new InvalidArgumentException(
				sprintf( 'Unerlaubtes Zeichen „%s" an Position %d.', $c, $i )
			);
		}

		$tokens[] = array(
			'type' => self::T_EOF,
			'pos'  => $len,
		);

		return $tokens;
	}

	/**
	 * OR-Ebene (schwächste Bindung).
	 *
	 * @return array<string, mixed>
	 */
	private function parse_or() {
		$left = $this->parse_and();

		while ( self::T_OR === $this->type() ) {
			$this->advance();
			$left = array(
				'type'  => 'or',
				'left'  => $left,
				'right' => $this->parse_and(),
			);
		}

		return $left;
	}

	/**
	 * AND-Ebene.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_and() {
		$left = $this->parse_not();

		while ( self::T_AND === $this->type() ) {
			$this->advance();
			$left = array(
				'type'  => 'and',
				'left'  => $left,
				'right' => $this->parse_not(),
			);
		}

		return $left;
	}

	/**
	 * NOT-Ebene.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_not() {
		if ( self::T_NOT === $this->type() ) {
			$this->advance();
			return array(
				'type' => 'not',
				'expr' => $this->parse_not(),
			);
		}

		return $this->parse_primary();
	}

	/**
	 * Primärausdruck: Conditional Tag oder geklammerter Ausdruck.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException Bei unvollständigem Ausdruck.
	 */
	private function parse_primary() {
		$token = $this->token();

		if ( self::T_COND === $token['type'] ) {
			$this->advance();
			return array(
				'type' => 'cond',
				'name' => $token['value'],
			);
		}

		if ( self::T_LPAREN === $token['type'] ) {
			$this->advance();
			$expr = $this->parse_or();

			if ( self::T_RPAREN !== $this->type() ) {
				$current = $this->token();
				throw new InvalidArgumentException(
					sprintf( 'Schließende Klammer fehlt vor Position %d.', (int) $current['pos'] )
				);
			}

			$this->advance();
			return $expr;
		}

		throw new InvalidArgumentException(
			sprintf(
				'Erwartet wurde ein Conditional Tag oder „(" an Position %d.',
				isset( $token['pos'] ) ? (int) $token['pos'] : 0
			)
		);
	}

	/**
	 * Wertet einen AST-Knoten aus.
	 *
	 * @param array<string, mixed> $node Knoten.
	 * @return bool
	 */
	private function run_ast( array $node ) {
		switch ( $node['type'] ) {
			case 'empty':
				return true;
			case 'cond':
				return $this->run_condition( $node['name'] );
			case 'not':
				return ! $this->run_ast( $node['expr'] );
			case 'and':
				return $this->run_ast( $node['left'] ) && $this->run_ast( $node['right'] );
			case 'or':
				return $this->run_ast( $node['left'] ) || $this->run_ast( $node['right'] );
		}

		return false;
	}

	/**
	 * Ruft einen einzelnen, freigegebenen Conditional Tag auf.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private function run_condition( $name ) {
		$callback = isset( $this->allowed[ $name ] ) ? $this->allowed[ $name ] : null;

		if ( null === $callback || ! is_callable( $callback ) ) {
			return false;
		}

		return (bool) call_user_func( $callback );
	}

	/**
	 * Aktuelles Token.
	 *
	 * @return array<string, mixed>
	 */
	private function token() {
		return $this->tokens[ $this->pos ];
	}

	/**
	 * Typ des aktuellen Tokens.
	 *
	 * @return string
	 */
	private function type() {
		$token = $this->token();
		return $token['type'];
	}

	/**
	 * Eine Position weiter.
	 *
	 * @return void
	 */
	private function advance() {
		++$this->pos;
	}
}

/**
 * Bindet das Bedingungsfeld an klassische Widgets und wertet es im Frontend aus.
 */
final class WCT_Widget_Field {

	const FIELD = '_wct_condition';
	const ERROR = '_wct_condition_error';

	/**
	 * Parser.
	 *
	 * @var WCT_Condition_Parser
	 */
	private $parser;

	/**
	 * Merker, damit das Inline-CSS nur einmal pro Seite ausgegeben wird.
	 *
	 * @var bool
	 */
	private $style_done = false;

	/**
	 * Konstruktor.
	 *
	 * @param WCT_Condition_Parser $parser Parser.
	 */
	public function __construct( WCT_Condition_Parser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * Hooks registrieren.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'in_widget_form', array( $this, 'render_field' ), 10, 3 );
		add_filter( 'widget_update_callback', array( $this, 'update_instance' ), 10, 4 );
		add_filter( 'widget_display_callback', array( $this, 'maybe_display' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'maybe_block_editor_notice' ) );
	}

	/**
	 * Rendert das Bedingungsfeld unter einem klassischen Widget.
	 *
	 * @param WP_Widget   $widget   Widget-Objekt.
	 * @param null|string $return   Rückgabewert des Widget-Formulars.
	 * @param array       $instance Widget-Instanz.
	 * @return void
	 */
	public function render_field( $widget, $return, $instance ) {
		$condition = isset( $instance[ self::FIELD ] ) ? (string) $instance[ self::FIELD ] : '';
		$error     = isset( $instance[ self::ERROR ] ) ? (string) $instance[ self::ERROR ] : '';
		$field_id  = $widget->get_field_id( self::FIELD );
		$name      = $widget->get_field_name( self::FIELD );
		$allowed   = array_keys( WCT_Condition_Parser::allowed_conditions() );
		$examples  = array(
			'is_home() OR is_archive()',
			'is_paged() OR is_archive()',
			'(is_single() OR is_page()) AND !is_paged()',
			'!is_search()',
		);

		$this->print_style_once();
		?>
		<div class="wct-condition">
			<p>
				<label for="<?php echo esc_attr( $field_id ); ?>">
					<strong><?php esc_html_e( 'Anzeige-Bedingung', 'widget-conditional-tags' ); ?></strong>
				</label>
				<textarea
					class="widefat code"
					rows="3"
					id="<?php echo esc_attr( $field_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					placeholder="is_home() OR is_archive()"><?php echo esc_textarea( $condition ); ?></textarea>
			</p>

			<?php if ( '' !== $error ) : ?>
				<p class="wct-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Leer lassen = überall anzeigen. Erlaubt sind !, AND, OR, &&, || und Klammern. Es wird kein PHP-Code ausgeführt.', 'widget-conditional-tags' ); ?>
			</p>

			<details class="wct-help">
				<summary><?php esc_html_e( 'Beispiele und erlaubte Conditional Tags', 'widget-conditional-tags' ); ?></summary>
				<p><strong><?php esc_html_e( 'Beispiele:', 'widget-conditional-tags' ); ?></strong></p>
				<ul>
					<?php foreach ( $examples as $example ) : ?>
						<li><code><?php echo esc_html( $example ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<p><strong><?php esc_html_e( 'Erlaubt:', 'widget-conditional-tags' ); ?></strong></p>
				<p class="wct-allowed"><code><?php echo esc_html( implode( ', ', $allowed ) ); ?></code></p>
			</details>
		</div>
		<?php
	}

	/**
	 * Speichert und validiert das Bedingungsfeld.
	 *
	 * @param array     $instance     Vom Widget bereinigte neue Instanz.
	 * @param array     $new_instance Rohe neue Instanz.
	 * @param array     $old_instance Alte Instanz.
	 * @param WP_Widget $widget       Widget-Objekt.
	 * @return array
	 */
	public function update_instance( $instance, $new_instance, $old_instance, $widget ) {
		// Der Standard-Widget-Speicherpfad ist bereits Nonce-geschützt;
		// hier zusätzlich eine Capability-Prüfung als Defense-in-Depth.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $old_instance;
		}

		$raw       = isset( $new_instance[ self::FIELD ] ) ? wp_unslash( $new_instance[ self::FIELD ] ) : '';
		$condition = $this->normalize( sanitize_textarea_field( $raw ) );

		unset( $instance[ self::ERROR ] );

		if ( '' === $condition ) {
			unset( $instance[ self::FIELD ] );
			return $instance;
		}

		try {
			$this->parser->parse( $condition );
			$instance[ self::FIELD ] = $condition;
		} catch ( InvalidArgumentException $exception ) {
			if ( isset( $old_instance[ self::FIELD ] ) && '' !== trim( (string) $old_instance[ self::FIELD ] ) ) {
				$instance[ self::FIELD ] = $old_instance[ self::FIELD ];
			} else {
				unset( $instance[ self::FIELD ] );
			}

			$instance[ self::ERROR ] = sprintf(
				/* translators: %s: Fehlermeldung des Parsers. */
				__( 'Die Bedingung wurde nicht übernommen: %s', 'widget-conditional-tags' ),
				$exception->getMessage()
			);
		}

		return $instance;
	}

	/**
	 * Zeigt ein Widget je nach Bedingung an oder blendet es aus.
	 *
	 * @param array|false $instance Aktuelle Instanz.
	 * @param WP_Widget   $widget   Widget.
	 * @param array       $args     Widget-Argumente.
	 * @return array|false
	 */
	public function maybe_display( $instance, $widget, $args ) {
		if ( false === $instance || is_admin() ) {
			return $instance;
		}

		if ( ! is_array( $instance ) || empty( $instance[ self::FIELD ] ) ) {
			return $instance;
		}

		$condition = $this->normalize( (string) $instance[ self::FIELD ] );

		if ( '' === $condition ) {
			return $instance;
		}

		try {
			return $this->parser->evaluate( $condition ) ? $instance : false;
		} catch ( InvalidArgumentException $exception ) {
			// Gespeicherte Bedingungen werden beim Speichern validiert.
			// Rutscht doch eine ungültige durch (etwa weil ein per Filter
			// ergänzter Tag nicht mehr verfügbar ist), wird das Widget
			// vorsorglich ausgeblendet. Fail closed: das Verhalten bleibt
			// vorhersagbar, statt im Fehlerfall Richtung „anzeigen" zu kippen.
			return false;
		}
	}

	/**
	 * Weist im Backend darauf hin, dass das Plugin die klassische
	 * Widget-Oberfläche braucht, wenn der Block-Widget-Editor aktiv ist.
	 *
	 * In dem Fall steht das Bedingungsfeld nicht zur Verfügung und die
	 * Anzeige-Steuerung greift nicht. Der Hinweis erscheint nur für Nutzer,
	 * die etwas ändern können, und nur auf der Widget- und der Plugin-Seite.
	 *
	 * @return void
	 */
	public function maybe_block_editor_notice() {
		if ( ! function_exists( 'wp_use_widgets_block_editor' ) || ! wp_use_widgets_block_editor() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof WP_Screen || ! in_array( $screen->id, array( 'widgets', 'plugins' ), true ) ) {
			return;
		}

		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'plugin-install.php?s=Classic+Widgets&tab=search&type=term' ) ),
			esc_html__( 'Classic Widgets', 'widget-conditional-tags' )
		);

		/* translators: %s: Link zum Plugin „Classic Widgets". */
		$hint = sprintf(
			wp_kses(
				__( 'Abhilfe: das Plugin %s installieren und aktivieren.', 'widget-conditional-tags' ),
				array( 'a' => array( 'href' => array() ) )
			),
			$link
		);

		// $hint ist durch wp_kses() bereits abgesichert, daher hier roh ausgegeben.
		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p>%2$s</p></div>',
			esc_html__( 'Widget Conditional Tags ist für die klassische Widget-Oberfläche gebaut. Aktuell ist der Block-Widget-Editor aktiv – das Bedingungsfeld steht dort nicht zur Verfügung und die Anzeige-Steuerung greift nicht.', 'widget-conditional-tags' ),
			$hint
		);
	}

	/**
	 * Vereinheitlicht Whitespace im Ausdruck.
	 *
	 * @param string $expression Roher Ausdruck.
	 * @return string
	 */
	private function normalize( $expression ) {
		$expression = trim( (string) $expression );
		$expression = preg_replace( '/\s+/', ' ', $expression );

		return null === $expression ? '' : $expression;
	}

	/**
	 * Gibt das Backend-CSS einmal pro Seite aus.
	 *
	 * Bewusst inline gehalten, damit das Plugin eine einzige Datei bleibt.
	 *
	 * @return void
	 */
	private function print_style_once() {
		if ( $this->style_done ) {
			return;
		}

		$this->style_done = true;

		echo '<style>
.wct-condition{border-top:1px solid #dcdcde;margin-top:12px;padding-top:10px}
.wct-condition textarea.code{font-family:Consolas,Monaco,monospace}
.wct-error{border-left:4px solid #d63638;background:#fcf0f1;margin:8px 0;padding:8px 10px}
.wct-help{margin-top:8px}
.wct-help summary{cursor:pointer}
.wct-allowed{max-height:100px;overflow:auto}
</style>';
	}
}

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'widget-conditional-tags',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		$field = new WCT_Widget_Field( new WCT_Condition_Parser() );
		$field->init();
	}
);
