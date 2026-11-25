<?php
/**
 * Mise en forme des champs personnalisés pour l'IA.
 *
 * @package W_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WMCP_Field_Presenter
 *
 * Réduit une définition de champ ACF à ce qu'il faut pour comprendre la
 * structure d'un contenu : nom, type, contraintes, sous-champs. Tout le reste
 * (réglages d'affichage, clés internes, logique conditionnelle) est du bruit
 * qui coûterait des tokens sans rien apprendre.
 *
 * Logique pure : aucune fonction WordPress, testable avec PHPUnit seul.
 */
class WMCP_Field_Presenter {

	/**
	 * Types ACF qui ne portent aucune donnée (mise en page du formulaire).
	 *
	 * @var string[]
	 */
	const LAYOUT_ONLY_TYPES = array( 'tab', 'message', 'accordion' );

	/**
	 * Profondeur maximale des sous-champs décrits.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 4;

	/**
	 * Nombre maximal de choix listés pour un champ à options.
	 *
	 * @var int
	 */
	const MAX_CHOICES = 30;

	/**
	 * Longueur maximale des consignes reprises.
	 *
	 * @var int
	 */
	const MAX_INSTRUCTIONS_LENGTH = 200;

	/**
	 * Décrit une liste de champs ACF.
	 *
	 * @param mixed $fields Champs ACF, tels que rendus par `acf_get_fields()`.
	 * @param int   $depth  Profondeur courante.
	 * @return array<int,array<string,mixed>>
	 */
	public function present_fields( $fields, $depth = 1 ) {
		$presented = array();

		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) || in_array( $field['type'] ?? '', self::LAYOUT_ONLY_TYPES, true ) ) {
				continue;
			}

			$presented[] = $this->present_field( $field, $depth );
		}

		return $presented;
	}

	/**
	 * Compte les champs porteurs de données d'une liste, hors mise en page.
	 *
	 * @param mixed $fields Champs ACF.
	 * @return int
	 */
	public function count_fields( $fields ) {
		return count( $this->present_fields( $fields, self::MAX_DEPTH ) );
	}

	/**
	 * Décrit un champ ACF.
	 *
	 * @param array<string,mixed> $field Champ ACF.
	 * @param int                 $depth Profondeur courante.
	 * @return array<string,mixed>
	 */
	private function present_field( array $field, $depth ) {
		$presented = array(
			'name'  => (string) ( $field['name'] ?? '' ),
			'label' => (string) ( $field['label'] ?? '' ),
			'type'  => (string) ( $field['type'] ?? '' ),
		);

		if ( ! empty( $field['required'] ) ) {
			$presented['required'] = true;
		}

		$instructions = trim( (string) ( $field['instructions'] ?? '' ) );
		if ( '' !== $instructions ) {
			$presented['instructions'] = substr( $instructions, 0, self::MAX_INSTRUCTIONS_LENGTH );
		}

		$presented += $this->present_constraints( $field );

		if ( $depth >= self::MAX_DEPTH ) {
			return $presented;
		}

		if ( ! empty( $field['sub_fields'] ) ) {
			$presented['sub_fields'] = $this->present_fields( $field['sub_fields'], $depth + 1 );
		}

		if ( ! empty( $field['layouts'] ) ) {
			$presented['layouts'] = $this->present_layouts( $field['layouts'], $depth + 1 );
		}

		return $presented;
	}

	/**
	 * Retient ce qui borne les valeurs possibles d'un champ.
	 *
	 * @param array<string,mixed> $field Champ ACF.
	 * @return array<string,mixed>
	 */
	private function present_constraints( array $field ) {
		$constraints = array();

		if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
			$constraints['choices'] = array_slice( $field['choices'], 0, self::MAX_CHOICES, true );
		}

		if ( ! empty( $field['multiple'] ) ) {
			$constraints['multiple'] = true;
		}

		// Champs qui pointent vers d'autres contenus : dire vers quoi.
		foreach ( array( 'post_type', 'taxonomy' ) as $target ) {
			if ( ! empty( $field[ $target ] ) ) {
				$constraints[ $target ] = $field[ $target ];
			}
		}

		if ( ! empty( $field['return_format'] ) && is_string( $field['return_format'] ) ) {
			$constraints['return_format'] = $field['return_format'];
		}

		return $constraints;
	}

	/**
	 * Décrit les dispositions d'un contenu flexible.
	 *
	 * @param mixed $layouts Dispositions ACF.
	 * @param int   $depth   Profondeur des sous-champs.
	 * @return array<int,array<string,mixed>>
	 */
	private function present_layouts( $layouts, $depth ) {
		$presented = array();

		foreach ( (array) $layouts as $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}

			$presented[] = array(
				'name'       => (string) ( $layout['name'] ?? '' ),
				'label'      => (string) ( $layout['label'] ?? '' ),
				'sub_fields' => $this->present_fields( $layout['sub_fields'] ?? array(), $depth ),
			);
		}

		return $presented;
	}
}
