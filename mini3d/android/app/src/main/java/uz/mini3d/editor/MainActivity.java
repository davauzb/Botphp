package uz.mini3d.editor;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ContentValues;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.view.View;
import android.view.WindowInsets;
import android.webkit.JavascriptInterface;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.Toast;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.HashMap;
import java.util.Iterator;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import org.json.JSONObject;

/**
 * Mini 3D Muharrir — asosiy oyna.
 * Butun muharrir assets/ ichidagi veb-ilova (three.js) sifatida WebView'da ishlaydi.
 * Bu klass faqat quyidagilarni ta'minlaydi:
 *   - fayl tanlash (import, tekstura rasmlari)
 *   - fayllarni "Download/Mini3D" papkasiga saqlash va ulashish (AndroidBridge)
 *   - "Orqaga" tugmasi, tizim panellari uchun joy (insets)
 */
public class MainActivity extends Activity {
    private static final int REQ_FILE = 1001;
    private static final int REQ_PERM = 1002;
    private static final String FOLDER = "Mini3D";

    private static final String ASSET_HOST = "appassets.androidplatform.net";

    private WebView web;
    private ValueCallback<Uri[]> fileCallback;
    private final ExecutorService pool = Executors.newFixedThreadPool(3);

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.parseColor("#14171C"));
        web = new WebView(this);
        web.setBackgroundColor(Color.parseColor("#14171C"));
        root.addView(web, new FrameLayout.LayoutParams(-1, -1));
        setContentView(root);

        // Android 15+ da ilova butun ekranni egallaydi — tizim panellari uchun joy qoldiramiz
        root.setOnApplyWindowInsetsListener((v, insets) -> {
            int l, t, r, b;
            if (Build.VERSION.SDK_INT >= 30) {
                android.graphics.Insets i = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout() | WindowInsets.Type.ime());
                l = i.left; t = i.top; r = i.right; b = i.bottom;
            } else {
                l = insets.getSystemWindowInsetLeft(); t = insets.getSystemWindowInsetTop();
                r = insets.getSystemWindowInsetRight(); b = insets.getSystemWindowInsetBottom();
            }
            v.setPadding(l, t, r, b);
            return insets;
        });

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setAllowFileAccess(true);
        s.setAllowContentAccess(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        s.setSupportZoom(false);
        s.setTextZoom(100);

        web.addJavascriptInterface(new Bridge(), "AndroidBridge");
        web.setWebViewClient(new WebViewClient() {
            /**
             * Virtual host: https://appassets.androidplatform.net/...
             *  /ort/*  — ilova ichidagi AI runtime (wasm), internetsiz
             *  /tmp/*  — Java yuklab olgan katta fayllar (3D modellar)
             */
            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                if (!ASSET_HOST.equals(u.getHost())) return null;
                String path = u.getPath() == null ? "" : u.getPath();
                Map<String, String> h = new HashMap<>();
                h.put("Access-Control-Allow-Origin", "*");
                h.put("Cache-Control", "no-cache");
                try {
                    InputStream in;
                    if (path.startsWith("/tmp/")) {
                        File f = new File(new File(getCacheDir(), "http"), path.substring(5).replaceAll("[^A-Za-z0-9_.-]", ""));
                        in = new FileInputStream(f);
                    } else {
                        in = getAssets().open(path.startsWith("/") ? path.substring(1) : path);
                    }
                    String mime = path.endsWith(".wasm") ? "application/wasm"
                            : path.endsWith(".mjs") || path.endsWith(".js") ? "text/javascript"
                            : "application/octet-stream";
                    return new WebResourceResponse(mime, null, 200, "OK", h, in);
                } catch (Exception e) {
                    return new WebResourceResponse("text/plain", "utf-8", 404, "Not found", h, null);
                }
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                if ("file".equals(u.getScheme())) return false;
                // Tashqi havolalar brauzerda ochiladi (ko'prik ularga berilmaydi)
                try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception ignored) { }
                return true;
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = callback;
                Intent intent = new Intent(Intent.ACTION_GET_CONTENT);
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                String[] types = params.getAcceptTypes();
                boolean images = types != null && types.length > 0 && types[0] != null && types[0].startsWith("image");
                // .glb/.stl kabi fayllarning MIME turi telefonlarda turlicha — hammasini ko'rsatamiz
                intent.setType(images ? "image/*" : "*/*");
                try {
                    startActivityForResult(Intent.createChooser(intent, null), REQ_FILE);
                } catch (Exception e) {
                    fileCallback = null;
                    return false;
                }
                return true;
            }
        });

        if (savedInstanceState != null) web.restoreState(savedInstanceState);
        else web.loadUrl("file:///android_asset/index.html");
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQ_FILE) {
            if (fileCallback != null) {
                Uri[] result = null;
                if (resultCode == RESULT_OK && data != null && data.getData() != null) result = new Uri[]{data.getData()};
                fileCallback.onReceiveValue(result);
                fileCallback = null;
            }
            return;
        }
        super.onActivityResult(requestCode, resultCode, data);
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        web.saveState(outState);
    }

    @SuppressWarnings("deprecation")
    @Override
    public void onBackPressed() {
        // Avval veb-ilovaga beramiz: oyna yopish, tanlovni bekor qilish va h.k.
        web.evaluateJavascript("(window.__onBack && window.__onBack()) ? 'yes' : 'no'", value -> {
            if (value == null || !value.contains("yes")) MainActivity.super.onBackPressed();
        });
    }

    @Override
    protected void onPause() {
        super.onPause();
        web.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        web.onResume();
    }

    @Override
    protected void onDestroy() {
        pool.shutdownNow();
        // vaqtinchalik yuklab olingan fayllarni tozalaymiz
        File dir = new File(getCacheDir(), "http");
        File[] fs = dir.listFiles();
        if (fs != null) for (File f : fs) f.delete();
        if (web != null) web.destroy();
        super.onDestroy();
    }

    // ================= JavaScript ko'prigi =================
    private class Bridge {
        /**
         * Asinxron HTTP so'rov (CORS cheklovisiz). Natija:
         *   window.__httpDone(id, status, bodyBase64 | tmpUrl, error)
         * responseType = "file" bo'lsa — javob faylga yoziladi va virtual URL qaytadi.
         */
        @JavascriptInterface
        public void httpRequest(String id, String method, String url, String headersJson, String bodyB64, String responseType, int timeoutMs) {
            pool.execute(() -> {
                HttpURLConnection c = null;
                try {
                    c = (HttpURLConnection) new URL(url).openConnection();
                    c.setRequestMethod(method);
                    c.setConnectTimeout(30000);
                    c.setReadTimeout(Math.max(30000, timeoutMs));
                    c.setInstanceFollowRedirects(true);
                    JSONObject hs = new JSONObject(headersJson == null || headersJson.isEmpty() ? "{}" : headersJson);
                    for (Iterator<String> it = hs.keys(); it.hasNext(); ) {
                        String k = it.next();
                        c.setRequestProperty(k, hs.getString(k));
                    }
                    if (bodyB64 != null && !bodyB64.isEmpty()) {
                        c.setDoOutput(true);
                        byte[] b = Base64.decode(bodyB64, Base64.DEFAULT);
                        c.setFixedLengthStreamingMode(b.length);
                        try (OutputStream os = c.getOutputStream()) { os.write(b); }
                    }
                    int status = c.getResponseCode();
                    InputStream in = status >= 400 ? c.getErrorStream() : c.getInputStream();
                    String result;
                    if ("file".equals(responseType) && status < 400) {
                        File dir = new File(getCacheDir(), "http");
                        if (!dir.exists()) dir.mkdirs();
                        String name = "r" + id + "_" + System.currentTimeMillis() + ".bin";
                        try (FileOutputStream fos = new FileOutputStream(new File(dir, name))) {
                            byte[] buf = new byte[65536];
                            int n;
                            while (in != null && (n = in.read(buf)) > 0) fos.write(buf, 0, n);
                        }
                        result = "https://" + ASSET_HOST + "/tmp/" + name;
                    } else {
                        ByteArrayOutputStream bos = new ByteArrayOutputStream();
                        byte[] buf = new byte[65536];
                        int n;
                        while (in != null && (n = in.read(buf)) > 0) bos.write(buf, 0, n);
                        result = Base64.encodeToString(bos.toByteArray(), Base64.NO_WRAP);
                    }
                    deliver(id, status, result, null);
                } catch (Exception e) {
                    deliver(id, 0, "", String.valueOf(e.getMessage()));
                } finally {
                    if (c != null) c.disconnect();
                }
            });
        }

        @JavascriptInterface
        public String version() {
            try {
                return getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
            } catch (Exception e) {
                return "1.0";
            }
        }

        @JavascriptInterface
        public void toast(String msg) {
            runOnUiThread(() -> Toast.makeText(MainActivity.this, msg, Toast.LENGTH_SHORT).show());
        }

        /** Faylni Download/Mini3D ga saqlaydi. Qaytaradi: yo'l yoki "ERR:xabar". */
        @JavascriptInterface
        public String saveFile(String name, String mime, String base64) {
            try {
                write(name, mime, Base64.decode(base64, Base64.DEFAULT));
                return Environment.DIRECTORY_DOWNLOADS + "/" + FOLDER + "/" + name;
            } catch (Exception e) {
                return "ERR:" + e.getMessage();
            }
        }

        /** Saqlaydi va "Ulashish" oynasini ochadi (Telegram, Drive va h.k.). */
        @JavascriptInterface
        public String shareFile(String name, String mime, String base64) {
            try {
                Uri uri = write(name, mime, Base64.decode(base64, Base64.DEFAULT));
                if (uri != null) {
                    Intent send = new Intent(Intent.ACTION_SEND);
                    send.setType(mime);
                    send.putExtra(Intent.EXTRA_STREAM, uri);
                    send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                    runOnUiThread(() -> {
                        try { startActivity(Intent.createChooser(send, name)); } catch (Exception ignored) { }
                    });
                }
                return Environment.DIRECTORY_DOWNLOADS + "/" + FOLDER + "/" + name;
            } catch (Exception e) {
                return "ERR:" + e.getMessage();
            }
        }
    }

    private void deliver(String id, int status, String body, String err) {
        String js = "window.__httpDone(" + JSONObject.quote(id) + "," + status + "," + JSONObject.quote(body == null ? "" : body) + "," + (err == null ? "null" : JSONObject.quote(err)) + ")";
        runOnUiThread(() -> web.evaluateJavascript(js, null));
    }

    /** Android 10+ da MediaStore, eski versiyalarda oddiy fayl. Ulashish uchun content:// URI qaytaradi (10+). */
    private Uri write(String name, String mime, byte[] bytes) throws Exception {
        name = name.replaceAll("[\\\\/:*?\"<>|]", "_");
        if (Build.VERSION.SDK_INT >= 29) {
            ContentValues v = new ContentValues();
            v.put(MediaStore.MediaColumns.DISPLAY_NAME, name);
            v.put(MediaStore.MediaColumns.MIME_TYPE, mime);
            v.put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/" + FOLDER);
            Uri uri = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
            if (uri == null) throw new Exception("MediaStore insert failed");
            try (OutputStream os = getContentResolver().openOutputStream(uri)) {
                if (os == null) throw new Exception("Cannot open stream");
                os.write(bytes);
            }
            return uri;
        }
        if (checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            runOnUiThread(() -> requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_PERM));
            throw new Exception("Xotiraga yozish uchun ruxsat bering va qayta urinib ko'ring");
        }
        File dir = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS), FOLDER);
        if (!dir.exists() && !dir.mkdirs()) throw new Exception("Cannot create folder");
        File f = new File(dir, name);
        try (FileOutputStream fos = new FileOutputStream(f)) {
            fos.write(bytes);
        }
        return null;
    }
}
