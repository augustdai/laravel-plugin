<?php

namespace Jyim\LaravelPlugin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;
use Jyim\LaravelPlugin\Support\Assets\PluginAssets;

/**
 * 插件静态资源出口（私有化的那一半）。
 *
 * URL 与改造前的静态目录完全同形：GET /plugins/{alias}/{path}。
 * 区别是这个文件不再躺在 public/ 下由 Web 服务器直发，而是从这里发出，
 * 于是「谁能拿到某插件的素材」第一次有了可以下手脚的地方（鉴权、按公司隔离、
 * 插件停用后立刻 404），也彻底告别了「包内一份 + public 一份」的双份文件。
 *
 * 性能口径（必须讲清楚，否则这是净亏的改造）：
 *   走 PHP 意味着每个素材请求都要启动框架（本机实测 boot ≈ 41ms）。改造前
 *   nginx 直发静态文件大约是 1ms 级别，所以这里做三件事把成本压回去：
 *   1. 强缓存 + 协商缓存（max-age 与 ETag 可配），命中 304 时不读文件也不发体；
 *   2. 插件懒加载判定把 /plugin 前缀排除在外，素材请求不会注册任何插件 ServiceProvider；
 *   3. 生产环境可开 X-Accel-Redirect：PHP 只做「能不能给」的判定，字节由 nginx 发
 *      （config('plugins.assets.accel')，需要 nginx 配一个 internal location）。
 *   换句话说：需要鉴权时走 PHP，量大又不想付这份启动成本时开 accel 交给 nginx。
 */
class AssetController extends Controller
{
    public function __construct(private readonly PluginAssets $assets)
    {
    }

    /**
     * @param  string  $alias  插件别名（目录名小写，如 esmothree）
     * @param  string  $path   相对该插件 assets 根的路径
     */
    public function serve(Request $request, string $alias, string $path)
    {
        $file = $this->assets->find($alias, $path);
        if ($file === null) {
            abort(404);
        }

        $stat = (array) @stat($file);
        // ETag 用 mtime+size+ino，不用内容哈希：素材里混着几十 MB 的视频，
        // 每次请求都读全文算 hash 比直接把文件发出去还慢
        $etag = '"' . dechex((int) ($stat['mtime'] ?? 0)) . '-' . (int) ($stat['size'] ?? 0)
            . '-' . (int) ($stat['ino'] ?? 0) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', (int) ($stat['mtime'] ?? 0)) . ' GMT';

        $notModified = $request->headers->get('If-None-Match') === $etag
            || ((string) $request->headers->get('If-Modified-Since') === $lastModified);
        if ($notModified) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, must-revalidate']);
        }

        $maxAge = (int) config('plugins.assets.max_age', 86400);
        $common = [
            'ETag'                   => $etag,
            'Last-Modified'          => $lastModified,
            'Cache-Control'          => $maxAge > 0 ? "public, max-age={$maxAge}" : 'public, max-age=0, must-revalidate',
            // 素材与主站同源，sniff 会把误命名的 js/css 当脚本执行；nosniff 关掉这条路
            'X-Content-Type-Options' => 'nosniff',
        ];

        $accel = (array) config('plugins.assets.accel', []);
        $relative = ($accel['enabled'] ?? false) === true ? $this->assets->relativeToPluginsRoot($file) : null;
        if ($relative !== null) {
            // 只回一个内部重定向头，字节交给 nginx（见类注释第 3 点）。
            // 路径来自 PHP 已校验过的绝对路径，且必然在 plugins/ 根内，nginx 侧只需 internal location。
            return response('', 200, $common + [
                (string) ($accel['header'] ?? 'X-Accel-Redirect') => rtrim((string) ($accel['prefix'] ?? '/_plugin-assets'), '/')
                    . '/' . $relative,
                'Content-Type' => $this->mimeOf($file),
            ]);
        }

        return response()->file($file, $common + ['Content-Type' => $this->mimeOf($file)]);
    }

    private function mimeOf(string $file): string
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $guess = MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? null;

        // js/css 必须给出准确类型，浏览器在 strict mode / nosniff 下会拒绝执行
        // 类型不对的脚本；MimeTypes 对个别扩展名（如 mjs）没有映射时兜底
        if ($guess === null || $guess === 'text/plain') {
            $fallback = [
                'js'   => 'application/javascript',
                'mjs'  => 'text/javascript',
                'css'  => 'text/css',
                'map'  => 'application/json',
                'svg'  => 'image/svg+xml',
                'woff' => 'font/woff',
                'ttf'  => 'font/ttf',
            ][$ext] ?? null;
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return Str::contains((string) $guess, 'text/') || in_array($ext, ['js', 'css', 'svg', 'txt'], true)
            ? $guess . '; charset=utf-8'
            : (string) $guess;
    }
}
