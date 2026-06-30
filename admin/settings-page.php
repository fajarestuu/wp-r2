<div class="wrap">
    <h1>Cloudflare R2 Uploader</h1>
    <h2 class="nav-tab-wrapper">
        <a href="?page=wp-r2-uploader&tab=upload" class="nav-tab <?php echo (!isset($_GET['tab']) || $_GET['tab']=='upload')?'nav-tab-active':''; ?>">Upload</a>
        <a href="?page=wp-r2-uploader&tab=settings" class="nav-tab <?php echo (isset($_GET['tab']) && $_GET['tab']=='settings')?'nav-tab-active':''; ?>">R2 Settings</a>
        <a href="?page=wp-r2-uploader&tab=media" class="nav-tab <?php echo (isset($_GET['tab']) && $_GET['tab']=='media')?'nav-tab-active':''; ?>">Media Search</a>
    </h2>

    <div class="tab-content">
        <?php
        $tab = $_GET['tab'] ?? 'upload';
        if ($tab == 'upload') {
            ?>
            <h3>Upload Gambar</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="r2_file" />
                <br><br>
                
                <input type="submit" name="r2_upload_submit" class="button button-primary" value="Upload" />
            </form>
            <form method="post" >
                
                <textarea name="r2_base64" placeholder="Tempel base64 string disini" style="width:100%;height:100px;"></textarea>
                <br><br>
                <input type="submit" name="r2_upload_submit" class="button button-primary" value="Upload" />
            </form>
            <?php
            if (isset($_POST['r2_upload_submit'])) {
                if (!empty($_FILES['r2_file']['tmp_name'])) {
                    $file = $_FILES['r2_file']['tmp_name'];
                    $name = basename($_FILES['r2_file']['name']);
                    $res = wp_r2_upload_file($file, $name);
                } elseif (!empty($_POST['r2_base64'])) {
                    $res = wp_r2_upload_base64($_POST['r2_base64']);
                } else {
                    echo "<p style='color:red;'>Tidak ada file/base64 yang diupload.</p>";
                }
                if (!empty($res['url'])) {
                    echo "<p style='color:green;'>Upload berhasil: <a href='{$res['url']}' target='_blank'>{$res['url']}</a></p>";
                }
            }
        }
        elseif ($tab == 'settings') {
            ?>
            <form method="post" action="options.php">
                <?php settings_fields('wp_r2_settings'); ?>
                <?php do_settings_sections('wp_r2_settings'); ?>
                <table class="form-table">
                    <tr><th>Account ID</th><td><input type="text" name="wp_r2_account_id" value="<?php echo esc_attr(get_option('wp_r2_account_id')); ?>" /></td></tr>
                    <tr><th>Access Key</th><td><input type="text" name="wp_r2_access_key" value="<?php echo esc_attr(get_option('wp_r2_access_key')); ?>" /></td></tr>
                    <tr><th>Secret Key</th><td><input type="password" name="wp_r2_secret_key" value="<?php echo esc_attr(get_option('wp_r2_secret_key')); ?>" /></td></tr>
                    <tr><th>Bucket</th><td><input type="text" name="wp_r2_bucket" value="<?php echo esc_attr(get_option('wp_r2_bucket')); ?>" /></td></tr>
                    <tr><th>Endpoint (ex: https://<accountid>.r2.cloudflarestorage.com)</th><td><input type="text" name="wp_r2_endpoint" value="<?php echo esc_attr(get_option('wp_r2_endpoint')); ?>" style="width:100%;" /></td></tr>
                    <tr><th>Public CDN URL (ex: https://cdn.yourdomain.com)</th><td><input type="text" name="wp_r2_cdn_url" value="<?php echo esc_attr(get_option('wp_r2_cdn_url')); ?>" style="width:100%;" /><p class="description">This is the public URL used to serve R2 files. Set a custom domain in your Cloudflare R2 bucket settings and enter it here.</p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php
        }
        elseif ($tab == 'media') {
            $cdn_url = rtrim(get_option('wp_r2_cdn_url'), '/');
            if (!$cdn_url) {
                echo "<p style='color:orange;'>Please configure your Public CDN URL in the R2 Settings tab.</p>";
            }
            $files = wp_r2_list_files();
            if ($files) {
                    echo "<h3>Media di R2</h3>";
                    echo "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:15px;'>";
            
                    foreach ($files as $f) {
                        $url  = esc_url($f['url']);
                        $name = esc_html($f['name']);
                        $image_url = $cdn_url . '/' . ltrim($name, '/');
                        echo "<div style='border:1px solid #ccc;padding:8px;text-align:center;'>
                                
                                    <img src='{$image_url}' alt='{$name}'  data-url='{$image_url}'
                                        onclick='copyImageUrl(this)'  style='max-width:100%;max-height:120px;object-fit:cover;display:block;margin:0 auto 8px;' />
                                
                                <div style='font-size:12px;word-break:break-all;'>{$image_url}</div>
                              </div>";
                    }
            
                    echo "</div>";
                    echo "<script>
                    function copyImageUrl(el) {
                        var url = el.getAttribute('data-url');
                        navigator.clipboard.writeText(url).then(function() {
                            alert('Copied to clipboard: ' + url);
                        }, function(err) {
                            alert('Failed to copy: ' + err);
                        });
                    }
                    </script>";
            } else {
                echo "<p>Tidak ada file di bucket.</p>";
            }
        }
        ?>
    </div>
</div>
