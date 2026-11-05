<?php

namespace Jyim\LaravelPlugin\Support\Assets;

use Illuminate\Support\Str;
use Jyim\LaravelPlugin\Contracts\RepositoryInterface;

/**
 * 插件静态资源的定位与解析（私有化之后唯一的入口）。
 *
 * 为什么要它：改造前插件资源是被 `plugin:publish` 拷到 public/plugins/ 里由
 * Web 服务器直接发的 —— 等于「插件的静态文件永远是公开的」，而且发布这一步
 * 会让同一份文件在仓库里存在两处（包内一份、public 一份），改了包内忘记重发
 * 就出现线上还是旧图这种最难查的问题。现在资源留在插件自己的目录里
 * （plugins/{Plugin}/assets），URL 形状不变（/plugins/{alias}/...），
 * 但请求要经过 PHP：这样才有鉴权的位置（将来可以按公司/有效期拦），
 * 也只剩一份文件。
 *
 * 路径解析的安全边界（这个类只做定位，不响应请求）：
 *   1. alias 必须能在插件清单里找到，找不到的 alias 一律 null —— 不拿用户传的
 *      字符串去拼目录，这比「拼完再校验」可靠得多；
 *   2. 相对路径不许出现 ..、绝对路径、NUL、以 . 开头的文件；
 *   3. 命中后还要确认 realpath 落在插件目录内（双保险，防符号链接）；
 *   4. 只发可被浏览器消费的扩展名，.php/.inc/.htaccess 之类直接当不存在。
 */
class PluginAssets
{
    /**
     * 允许直接对外的静态资源扩展名。
     *
     * 不含 php/phtml/phar/inc/htaccess/svgz —— 插件目录里本来就有可执行代码，
     * 这一步是在保证「HTTP 面」只能读到素材，读不到源码之外的东西。
     * 含 svg：插件素材里确有 svg 图标，它是仓库作者提供的文件（不是访客上传），
     * 响应侧会带 nosniff，具体说明见 AssetController。
     */
    public const SERVEABLE_EXTENSIONS = [
        'css', 'js', 'mjs', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'svg',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'ogg', 'mp3', 'wav',
        'pdf', 'txt',
    ];

    /**
     * 候选根目录（相对插件根），按顺序命中即返回。
     *
     * 收两条是因为历史上有两种落法：新约定是插件自己的 assets/，
     * 而 `plugin:make` 生成的脚手架把素材放在 Resources/assets/（Mix 的入口）。
     * 不做这一步兼容，就得让 131 个插件同时改目录。
     */
    private const ROOTS = ['assets', 'Resources/assets'];

    public function __construct(private readonly RepositoryInterface $repository)
    {
    }

    /**
     * 解析某个插件资源文件的绝对路径；不存在或不允许外发时返回 null。
     */
    public function find(string $alias, string $relative): ?string
    {
        $relative = $this->normalize($relative);
        if ($relative === null) {
            return null;
        }

        $plugin = $this->repository->find($alias);
        if ($plugin === null) {
            return null;
        }

        $base = realpath($plugin->getPath());
        if ($base === false || ! is_dir($base)) {
            return null;
        }

        foreach (self::ROOTS as $root) {
            $candidate = $base . DIRECTORY_SEPARATOR . $root . DIRECTORY_SEPARATOR . $relative;
            $real = realpath($candidate);

            // 必须仍在该插件目录内：realpath 为 false 表示文件不存在，
            // 前缀判断防的是软链跳到插件目录外（例如链到 storage 或系统目录）
            if ($real === false || ! is_file($real) || ! Str::startsWith($real, $base . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (! in_array(strtolower((string) pathinfo($real, PATHINFO_EXTENSION)), self::SERVEABLE_EXTENSIONS, true)) {
                return null;
            }

            return $real;
        }

        return null;
    }

    /**
     * URL 形状：/plugins/{alias}/{path}。
     *
     * 与改造前 public/plugins/ 的静态路径完全一致，所以 131 个插件的 blade
     * 里那近千处引用一处都不用改 —— 改的只是「谁来发这个文件」。
     */
    /**
     * 把解析出来的绝对路径换算成「相对 plugins/ 根」的路径，例如
     * Abbvie/assets/images/kv.jpg。
     *
     * 为什么需要：X-Accel-Redirect 的价值是让 nginx 直接发字节，而 nginx 只能按
     * 「别名 → 目录」做映射；插件目录是 StudlyCase（Abbvie），URL 里的别名是小写
     * （abbvie），nginx 侧没法猜对。所以这里把 PHP 已经算好的真实相对路径交给它，
     * nginx 只需要一句 `alias {项目}/plugins/;`。
     */
    public function relativeToPluginsRoot(string $absolute): ?string
    {
        $root = rtrim((string) config('plugins.paths.plugins', base_path('plugins')), '/') . '/';
        $absolute = str_replace('\\', '/', $absolute);

        if (! str_starts_with($absolute, $root)) {
            return null;
        }

        return substr($absolute, strlen($root));
    }

    public function url(string $alias, string $relative): string
    {
        $prefix = trim((string) config('plugins.assets.route', '/plugins'), '/');

        return '/' . $prefix . '/' . strtolower(trim($alias, '/')) . '/' . ltrim($relative, '/');
    }

    /**
     * 归一化请求里的相对路径；不合法返回 null。
     *
     * 放在这里而不是控制器，是因为解析和拼 URL 都必须用同一套规则：
     * 两处各写一份的话，早晚会出现「控制器放行、解析越界」的缝隙。
     */
    private function normalize(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || str_starts_with($segment, '.')) {
                return null; // 含隐藏文件（.env/.gitkeep 之类）一律拒绝
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
