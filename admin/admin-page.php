<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$import_result    = null;
$size_result      = null;
$convert_result   = null;
$sync_order_result = null;

if ( isset( $_POST['itwc_import_submit'] ) ) {
    $separator      = isset( $_POST['itwc_separator'] ) ? sanitize_text_field( $_POST['itwc_separator'] ) : ',';
    $acf_field_name = isset( $_POST['itwc_acf_field'] ) ? sanitize_text_field( $_POST['itwc_acf_field'] ) : '';
    $importer       = new ITWC_CSV_Importer( $separator, $acf_field_name );
    $import_result  = $importer->process_upload();
}

if ( isset( $_POST['itwc_assign_size_submit'] ) ) {
    $size_value = isset( $_POST['itwc_standard_size'] ) ? sanitize_text_field( $_POST['itwc_standard_size'] ) : 'Talla Única';
    $assigner   = new ITWC_Standard_Size_Assigner( $size_value );
    $size_result = $assigner->process();
}

if ( isset( $_POST['itwc_sync_order_submit'] ) ) {
    $syncer = new ITWC_Variation_Order_Sync();
    $sync_order_result = $syncer->process();
}

if ( isset( $_POST['itwc_convert_submit'] ) ) {
    $convert_stock = isset( $_POST['itwc_convert_stock'] ) ? intval( $_POST['itwc_convert_stock'] ) : 10;
    $converter     = new ITWC_Simple_To_Variable_Converter( $convert_stock );
    $convert_result = $converter->process();
}

$current_separator = isset( $_POST['itwc_separator'] ) ? sanitize_text_field( $_POST['itwc_separator'] ) : ',';
$current_acf_field = isset( $_POST['itwc_acf_field'] ) ? sanitize_text_field( $_POST['itwc_acf_field'] ) : '';
$current_size      = isset( $_POST['itwc_standard_size'] ) ? sanitize_text_field( $_POST['itwc_standard_size'] ) : 'Talla Única';
$current_stock     = isset( $_POST['itwc_convert_stock'] ) ? intval( $_POST['itwc_convert_stock'] ) : 10;
?>

<div class="wrap itwc-wrap">
    <h1>Importar Etiquetas y Recomendaciones</h1>
    <p>Sube un archivo CSV con la columna <strong>Title</strong> (requerida) para emparejar productos por nombre. Columnas opcionales: <strong>ID</strong>, <strong>Product Tags</strong>, <strong>Recommended Products</strong>. Cualquier otra columna se tratará como un campo ACF (ej: <code>editor_de_tabla</code>).</p>

    <div class="itwc-card">
        <h2>Subir archivo CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'itwc_import_tags', 'itwc_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="itwc_csv_file">Archivo CSV</label></th>
                    <td>
                        <input type="file" name="itwc_csv_file" id="itwc_csv_file" accept=".csv" required />
                        <p class="description">Columna requerida: <strong>Title</strong>. Opcionales: ID, Product Tags, Recommended Products. Columnas adicionales = campos ACF. El emparejamiento se hace por nombre del producto.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="itwc_separator">Separador de etiquetas</label></th>
                    <td>
                        <input type="text" name="itwc_separator" id="itwc_separator" value="<?php echo esc_attr( $current_separator ); ?>" class="small-text" maxlength="5" />
                        <p class="description">Carácter que separa valores dentro de "Product Tags" y "Recommended Products". Ejemplos: <code>,</code> <code>|</code> <code>;</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="itwc_acf_field">Campo ACF (Recomendaciones)</label></th>
                    <td>
                        <input type="text" name="itwc_acf_field" id="itwc_acf_field" value="<?php echo esc_attr( $current_acf_field ); ?>" class="regular-text" placeholder="ej: producto_recomendado" />
                        <p class="description">Nombre del campo ACF Relationship donde se guardan las recomendaciones. Solo necesario si el CSV tiene la columna "Recommended Products".</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="itwc_import_submit" class="button button-primary" value="Importar Etiquetas" />
                <a href="#" class="button button-secondary" id="itwc-download-example">Descargar CSV de ejemplo</a>
            </p>
        </form>
    </div>

    <div class="itwc-card">
        <h2>Asignar talla estándar</h2>
        <p>Asigna automáticamente una talla estándar (atributo <code>pa_tallas</code>) a todos los productos que <strong>no tengan ninguna talla</strong> configurada.</p>
        <form method="post">
            <?php wp_nonce_field( 'itwc_assign_standard_size', 'itwc_nonce_size' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="itwc_standard_size">Valor de la talla</label></th>
                    <td>
                        <input type="text" name="itwc_standard_size" id="itwc_standard_size" value="<?php echo esc_attr( $current_size ); ?>" class="regular-text" />
                        <p class="description">Valor que se asignará como talla a los productos sin talla. Ejemplo: <code>Talla Única</code>, <code>Estándar</code>, <code>Free Size</code></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="itwc_assign_size_submit" class="button button-primary" value="Asignar talla estándar" onclick="return confirm('¿Estás seguro? Esto asignará la talla a todos los productos que no tengan tallas configuradas.');" />
            </p>
        </form>
    </div>

    <?php if ( $size_result ) : ?>
        <div class="itwc-card itwc-results">
            <div class="notice <?php echo $size_result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
                <p><strong><?php echo esc_html( $size_result['message'] ); ?></strong></p>
            </div>

            <?php if ( ! empty( $size_result['results'] ) ) : ?>
                <?php if ( $size_result['success'] && ! empty( $size_result['summary'] ) ) : ?>
                    <div class="itwc-summary">
                        <span class="itwc-badge itwc-badge-success"><?php echo intval( $size_result['summary']['success'] ); ?> actualizado(s)</span>
                        <span class="itwc-badge itwc-badge-error"><?php echo intval( $size_result['summary']['errors'] ); ?> error(es)</span>
                        <span class="itwc-badge itwc-badge-total"><?php echo intval( $size_result['summary']['skipped'] ); ?> ya tenían talla</span>
                    </div>
                <?php endif; ?>

                <table class="widefat striped itwc-results-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>Estado</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $size_result['results'] as $row ) : ?>
                            <tr class="itwc-row-<?php echo esc_attr( $row['status'] ); ?>">
                                <td><?php echo intval( $row['id'] ); ?></td>
                                <td><?php echo esc_html( $row['title'] ); ?></td>
                                <td>
                                    <?php
                                    $status_labels = array(
                                        'success' => 'Actualizado',
                                        'error'   => 'Error',
                                    );
                                    $label = isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : $row['status'];
                                    ?>
                                    <span class="itwc-status itwc-status-<?php echo esc_attr( $row['status'] ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $row['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="itwc-card">
        <h2>Convertir simples a variables</h2>
        <p>Convierte todos los productos <strong>simples</strong> que tengan el atributo <code>pa_tallas</code> a productos <strong>variables</strong>. Se creará una variación por cada talla asignada, heredando el precio del producto original.</p>
        <form method="post">
            <?php wp_nonce_field( 'itwc_convert_to_variable', 'itwc_nonce_convert' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="itwc_convert_stock">Stock por variación</label></th>
                    <td>
                        <input type="number" name="itwc_convert_stock" id="itwc_convert_stock" value="<?php echo esc_attr( $current_stock ); ?>" class="small-text" min="0" />
                        <p class="description">Cantidad de stock que se asignará a cada variación creada.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="itwc_convert_submit" class="button button-primary" value="Convertir a variables" onclick="return confirm('¿Estás seguro? Esto convertirá todos los productos simples con tallas a productos variables y creará las variaciones correspondientes.');" />
            </p>
        </form>
    </div>

    <?php if ( $convert_result ) : ?>
        <div class="itwc-card itwc-results">
            <div class="notice <?php echo $convert_result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
                <p><strong><?php echo esc_html( $convert_result['message'] ); ?></strong></p>
            </div>

            <?php if ( ! empty( $convert_result['results'] ) ) : ?>
                <?php if ( $convert_result['success'] && ! empty( $convert_result['summary'] ) ) : ?>
                    <div class="itwc-summary">
                        <span class="itwc-badge itwc-badge-success"><?php echo intval( $convert_result['summary']['success'] ); ?> convertido(s)</span>
                        <span class="itwc-badge itwc-badge-error"><?php echo intval( $convert_result['summary']['errors'] ); ?> error(es)</span>
                        <span class="itwc-badge itwc-badge-total"><?php echo intval( $convert_result['summary']['skipped'] ); ?> omitido(s)</span>
                    </div>
                <?php endif; ?>

                <table class="widefat striped itwc-results-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>Precio</th>
                            <th>Tallas</th>
                            <th>Variaciones</th>
                            <th>Estado</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $convert_result['results'] as $row ) : ?>
                            <tr class="itwc-row-<?php echo esc_attr( $row['status'] ); ?>">
                                <td><?php echo intval( $row['id'] ); ?></td>
                                <td><?php echo esc_html( $row['title'] ); ?></td>
                                <td><?php echo esc_html( $row['price'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row['tallas'] ); ?></td>
                                <td><?php echo intval( $row['created'] ); ?></td>
                                <td>
                                    <span class="itwc-status itwc-status-<?php echo esc_attr( $row['status'] ); ?>">
                                        <?php echo 'success' === $row['status'] ? 'Convertido' : 'Error'; ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $row['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="itwc-card">
        <h2>Sincronizar orden de tallas</h2>
        <p>Recorre todos los productos variables y reordena las tallas y variaciones según el orden configurado en <strong>Productos &gt; Atributos &gt; Tallas</strong>. No modifica precios ni stock.</p>
        <form method="post">
            <?php wp_nonce_field( 'itwc_sync_variation_order', 'itwc_nonce_sync_order' ); ?>
            <p class="submit">
                <input type="submit" name="itwc_sync_order_submit" class="button button-primary" value="Sincronizar orden de tallas" onclick="return confirm('¿Estás seguro? Esto reordenará las tallas y variaciones de todos los productos variables según el orden de la taxonomía.');" />
            </p>
        </form>
    </div>

    <?php if ( $sync_order_result ) : ?>
        <div class="itwc-card itwc-results">
            <div class="notice <?php echo $sync_order_result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
                <p><strong><?php echo esc_html( $sync_order_result['message'] ); ?></strong></p>
            </div>

            <?php if ( ! empty( $sync_order_result['results'] ) ) : ?>
                <?php if ( $sync_order_result['success'] && ! empty( $sync_order_result['summary'] ) ) : ?>
                    <div class="itwc-summary">
                        <span class="itwc-badge itwc-badge-success"><?php echo intval( $sync_order_result['summary']['updated'] ); ?> procesado(s)</span>
                        <span class="itwc-badge itwc-badge-total"><?php echo intval( $sync_order_result['summary']['skipped'] ); ?> omitido(s)</span>
                    </div>
                <?php endif; ?>

                <table class="widefat striped itwc-results-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>Tallas</th>
                            <th>Estado</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sync_order_result['results'] as $row ) : ?>
                            <tr class="itwc-row-<?php echo esc_attr( $row['status'] ); ?>">
                                <td><?php echo intval( $row['id'] ); ?></td>
                                <td><?php echo esc_html( $row['title'] ); ?></td>
                                <td><?php echo esc_html( $row['tallas'] ); ?></td>
                                <td>
                                    <?php
                                    $sync_labels = array(
                                        'success' => 'Reordenado',
                                        'synced'  => 'Sincronizado',
                                    );
                                    $label = isset( $sync_labels[ $row['status'] ] ) ? $sync_labels[ $row['status'] ] : $row['status'];
                                    ?>
                                    <span class="itwc-status itwc-status-<?php echo esc_attr( $row['status'] ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $row['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $import_result ) : ?>
        <div class="itwc-card itwc-results">
            <div class="notice <?php echo $import_result['success'] ? 'notice-success' : 'notice-error'; ?> inline">
                <p><strong><?php echo esc_html( $import_result['message'] ); ?></strong></p>
            </div>

            <?php if ( ! empty( $import_result['results'] ) ) : ?>
                <?php if ( $import_result['success'] && ! empty( $import_result['summary'] ) ) : ?>
                    <div class="itwc-summary">
                        <span class="itwc-badge itwc-badge-success"><?php echo intval( $import_result['summary']['success'] ); ?> exitoso(s)</span>
                        <span class="itwc-badge itwc-badge-error"><?php echo intval( $import_result['summary']['errors'] ); ?> error(es)</span>
                        <span class="itwc-badge itwc-badge-total"><?php echo intval( $import_result['summary']['total'] ); ?> total</span>
                    </div>
                <?php endif; ?>

                <table class="widefat striped itwc-results-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>Etiquetas</th>
                            <th>Recomendaciones</th>
                            <th>Campos ACF</th>
                            <th>Estado</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $import_result['results'] as $row ) : ?>
                            <tr class="itwc-row-<?php echo esc_attr( $row['status'] ); ?>">
                                <td><?php echo intval( $row['id'] ); ?></td>
                                <td><?php echo esc_html( $row['title'] ); ?></td>
                                <td><?php echo esc_html( $row['tags'] ); ?></td>
                                <td><?php echo esc_html( isset( $row['recommendations'] ) ? $row['recommendations'] : '' ); ?></td>
                                <td><?php echo esc_html( ! empty( $row['acf_fields'] ) ? implode( ', ', $row['acf_fields'] ) : '' ); ?></td>
                                <td>
                                    <?php
                                    $status_labels = array(
                                        'success' => 'Exitoso',
                                        'error'   => 'Error',
                                        'skipped' => 'Omitido',
                                    );
                                    $label = isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : $row['status'];
                                    ?>
                                    <span class="itwc-status itwc-status-<?php echo esc_attr( $row['status'] ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $row['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ( $sync_order_result && ! empty( $sync_order_result['debug_log'] ) ) : ?>
<script>
console.group('%c[ITWC DEBUG] Sincronizar Orden de Tallas', 'color: #fd7e14; font-weight: bold; font-size: 14px;');
<?php foreach ( $sync_order_result['debug_log'] as $log_line ) : ?>
console.log(<?php echo wp_json_encode( $log_line ); ?>);
<?php endforeach; ?>
console.groupEnd();
</script>
<?php endif; ?>

<?php if ( $convert_result && ! empty( $convert_result['debug_log'] ) ) : ?>
<script>
console.group('%c[ITWC DEBUG] Convertir Simples a Variables', 'color: #e83e8c; font-weight: bold; font-size: 14px;');
<?php foreach ( $convert_result['debug_log'] as $log_line ) : ?>
console.log(<?php echo wp_json_encode( $log_line ); ?>);
<?php endforeach; ?>
console.groupEnd();
</script>
<?php endif; ?>

<?php if ( $size_result && ! empty( $size_result['debug_log'] ) ) : ?>
<script>
console.group('%c[ITWC DEBUG] Asignar Talla Estándar', 'color: #28a745; font-weight: bold; font-size: 14px;');
<?php foreach ( $size_result['debug_log'] as $log_line ) : ?>
console.log(<?php echo wp_json_encode( $log_line ); ?>);
<?php endforeach; ?>
console.groupEnd();
</script>
<?php endif; ?>

<?php if ( $import_result && ! empty( $import_result['debug_log'] ) ) : ?>
<script>
console.group('%c[ITWC DEBUG] Import Tags WooCommerce', 'color: #0073aa; font-weight: bold; font-size: 14px;');
<?php foreach ( $import_result['debug_log'] as $log_line ) : ?>
console.log(<?php echo wp_json_encode( $log_line ); ?>);
<?php endforeach; ?>
console.groupEnd();
</script>
<?php endif; ?>

<script>
document.getElementById('itwc-download-example').addEventListener('click', function(e) {
    e.preventDefault();
    var csv = 'Title,Product Tags,Recommended Products\n';
    csv += 'Camisa Azul,"etiqueta1, etiqueta2","Pantalon Negro, Zapatos Rojos"\n';
    csv += 'Pantalon Negro,"oferta, nuevo","Camisa Azul"\n';
    csv += 'Zapatos Rojos,"destacado","Camisa Azul, Pantalon Negro"\n';

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'ejemplo-import-tags.csv';
    link.click();
});
</script>
