<?php

namespace WPGraphQL\BlockEditor\Type\InterfaceType;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use WPGraphQL\AppContext;
use WPGraphQL\Model\Post;
use WPGraphQL\Registry\TypeRegistry;
use WPGraphQL\Utils\Utils;

/**
 * Class EditorBlockInterface
 *
 * @package WPGraphQL\BlockEditor
 */
class EditorBlockInterface {

	/**
	 * @param array      $block   The block being resolved
	 * @param AppContext $context The AppContext
	 *
	 * @return mixed WP_Block_Type|null
	 */
	public static function get_block( array $block, AppContext $context ) {
		$registered_blocks = $context->config['registered_editor_blocks'];

		if ( ! isset( $block['blockName'] ) ) {
			return null;
		}

		if ( ! isset( $registered_blocks[ $block['blockName'] ] ) || ! $registered_blocks[ $block['blockName'] ] instanceof \WP_Block_Type ) {
			return null;
		}

		return $registered_blocks[ $block['blockName'] ];
	}

	/**
	 * @param TypeRegistry $type_registry
	 *
	 * @throws Exception
	 */
	public static function register_type( TypeRegistry $type_registry ) {

		register_graphql_interface_type( 'NodeWithEditorBlocks', [
			'description' => __( 'Node that has editor blocks associated with it', 'wp-graphql-block-editor' ),
			'eagerlyLoadType' => true,
			'fields'      => [
				'editorBlocks' => [
					'type'        => [
						'list_of' => 'EditorBlock',
					],
					'description' => __( 'List of editor blocks', 'wp-graphql-block-editor' ),
					'resolve'     => function( $node ) {

						$content = null;
						if ( $node instanceof Post ) {

							// @todo: this is restricted intentionally.
							// $content = $node->contentRaw;

							// This is the unrestricted version, but we need to
							// probably have a "Block" Model that handles
							// determining what fields should/should not be
							// allowed to be returned?
							$post = get_post( $node->databaseId );
							$content = $post->post_content;

						}

						if ( empty( $content ) ) {
							return [];
						}

						// Parse the blocks from HTML comments to an array of blocks
						$parsed_blocks = parse_blocks( $content );

						if ( empty( $parsed_blocks ) ) {
							return [];
						}

						// Filter out blocks that have no name
						$parsed_blocks = array_filter( $parsed_blocks, function( $parsed_block, $k ) {
							return isset( $parsed_block['blockName'] ) && ! empty( $parsed_block['blockName'] );
						}, ARRAY_FILTER_USE_BOTH );

						$parsed_blocks = array_map( function( $parsed_block ) {
							$parsed_block['nodeId'] = uniqid();
//							wp_send_json( $parsed_block );
							return $parsed_block;
						}, $parsed_blocks );

//						wp_send_json( serialize_blocks( $parsed_blocks ) );

						return $parsed_blocks;
					}
				],
			],
		] );

		// Register the EditorBlock Interface
		$editor_block_interface_fields = [
			'name'                    => [
				'type'        => 'String',
				'description' => __( 'The name of the Block', 'wp-graphql-block-editor' ),
			],
			'blockEditorCategoryName' => [
				'type'        => 'String',
				'description' => __( 'The name of the category the Block belongs to', 'wp-graphql-block-editor' ),
				'resolve'     => function( $block, $args, AppContext $context, ResolveInfo $info ) {
					return isset( self::get_block( $block, $context )->category ) ? self::get_block( $block, $context )->category : null;
				}
			],
			'isDynamic'               => [
				'type'        => [ 'non_null' => 'Boolean' ],
				'description' => __( 'Whether the block is Dynamic (server rendered)', 'wp-graphql-block-editor' ),
				'resolve'     => function( $block, $args, AppContext $context, ResolveInfo $info ) {
					return isset( self::get_block( $block, $context )->render_callback ) && ! empty( self::get_block( $block, $context )->render_callback );
				},
			],
			'apiVersion'              => [
				'type'        => 'Integer',
				'description' => __( 'The API version of the Gutenberg Block', 'wp-graphql-block-editor' ),
				'resolve'     => function( $block, $args, AppContext $context, ResolveInfo $info ) {
					return isset( self::get_block( $block, $context )->api_version ) && absint( self::get_block( $block, $context )->api_version ) ? absint( self::get_block( $block, $context )->api_version ) : 2;
				},
			],
//				'supports'                => [
//					'type'        => 'EditorBlockSupports',
//					'description' => __( 'Features supported by the block', 'wp-graphql-block-editor' ),
//					'resolve'     => function( $block ) {
//						return isset( $block['supports'] ) && is_array( $block['supports'] ) ? $block['supports'] : [];
//					},
//				],
			'cssClassNames'           => [
				'type'        => [ 'list_of' => 'String' ],
				'description' => __( 'CSS Classnames to apply to the block', 'wp-graphql-block-editor' ),
				'resolve'     => function( $block ) {
					if ( isset( $block['attrs']['className'] ) ) {
						return explode( ' ', $block['attrs']['className'] );
					}

					return null;
				}
			],
			'renderedHtml' => [
				'type' => 'String',
				'description' => __( 'The rendered HTML for the block', 'wp-graphql-block-editor' ),
				'resolve' => function( $block ) {
					return render_block( $block );
				}
			],
			'attributes' => [
				'type' => 'String',
				'description' => __( 'The rendered HTML for the block', 'wp-graphql-block-editor' ),
				'resolve' => function( $block ) {
					$attrs = wp_json_encode( $block['attrs'] );
					return stripslashes( $attrs );
				}
			],
		];
		
		$editor_block_interface_fields = apply_filters( 'graphql_editor_block_fields' , $editor_block_interface_fields, $block );
		
		register_graphql_interface_type( 'EditorBlock', [
			'eagerlyLoadType' => true,
			'description' => __( 'Blocks that can be edited to create content and layouts', 'wp-graphql-block-editor' ),
			'fields'      => $editor_block_interface_fields,
			'resolveType' => function( $block ) use ( $type_registry ) {

				if ( empty( $block['blockName'] ) ) {
					$block['blockName'] = 'core/html';
				}

				$type_name = lcfirst( ucwords( $block['blockName'], '/' ) );
				$type_name = preg_replace( '/\//', '', lcfirst( ucwords( $type_name, '/' ) ) );
				$type_name = Utils::format_type_name( $type_name );

				return $type_registry->get_type( $type_name );
			}
		] );
	}
}
