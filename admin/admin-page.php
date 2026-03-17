<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$import_result = null;
$size_result   = null;

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

$current_separator = isset( $_POST['itwc_separator'] ) ? sanitize_text_field( $_POST['itwc_separator'] ) : ',';
$current_acf_field = isset( $_POST['itwc_acf_field'] ) ? sanitize_text_field( $_POST['itwc_acf_field'] ) : '';
$current_size      = isset( $_POST['itwc_standard_size'] ) ? sanitize_text_field( $_POST['itwc_standard_size'] ) : 'Talla Única';
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
