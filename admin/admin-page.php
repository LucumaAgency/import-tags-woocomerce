<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$import_result = null;

if ( isset( $_POST['itwc_import_submit'] ) ) {
    $separator     = isset( $_POST['itwc_separator'] ) ? sanitize_text_field( $_POST['itwc_separator'] ) : ',';
    $importer      = new ITWC_CSV_Importer( $separator );
    $import_result = $importer->process_upload();
}

$current_separator = isset( $_POST['itwc_separator'] ) ? sanitize_text_field( $_POST['itwc_separator'] ) : ',';
?>

<div class="wrap itwc-wrap">
    <h1>Importar Etiquetas de Productos</h1>
    <p>Sube un archivo CSV con las columnas <strong>ID</strong>, <strong>Title</strong> y <strong>Product Tags</strong> para asignar etiquetas a tus productos de WooCommerce.</p>

    <div class="itwc-card">
        <h2>Subir archivo CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'itwc_import_tags', 'itwc_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="itwc_csv_file">Archivo CSV</label></th>
                    <td>
                        <input type="file" name="itwc_csv_file" id="itwc_csv_file" accept=".csv" required />
                        <p class="description">Formato esperado: ID, Title, Product Tags.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="itwc_separator">Separador de etiquetas</label></th>
                    <td>
                        <input type="text" name="itwc_separator" id="itwc_separator" value="<?php echo esc_attr( $current_separator ); ?>" class="small-text" maxlength="5" />
                        <p class="description">Carácter que separa las etiquetas dentro del campo "Product Tags". Ejemplos: <code>,</code> <code>|</code> <code>;</code></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="itwc_import_submit" class="button button-primary" value="Importar Etiquetas" />
                <a href="#" class="button button-secondary" id="itwc-download-example">Descargar CSV de ejemplo</a>
            </p>
        </form>
    </div>

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

<script>
document.getElementById('itwc-download-example').addEventListener('click', function(e) {
    e.preventDefault();
    var csv = 'ID,Title,Product Tags\n';
    csv += '101,Producto Ejemplo 1,"etiqueta1, etiqueta2, etiqueta3"\n';
    csv += '102,Producto Ejemplo 2,"oferta, nuevo"\n';
    csv += '103,Producto Ejemplo 3,"destacado"\n';

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'ejemplo-import-tags.csv';
    link.click();
});
</script>
