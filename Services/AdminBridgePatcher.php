<?php

namespace Plugin\SmartExpiry\Services;

use RuntimeException;

final class AdminBridgePatcher
{
    public const MARKER = 'smart-expiry-v2';
    public const LEGACY_MARKER = 'smart-expiry-v1';

    private const LOCALE_FILES = [
        'public/assets/admin/locales/en-US.js',
        'public/assets/admin/locales/ru-RU.js',
        'public/assets/admin/locales/zh-CN.js',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    public function apply(): string
    {
        [$paths, $state] = $this->validateFiles();
        if ($state === 'v2') {
            return 'already patched';
        }

        $patched = [];
        foreach ($paths as $relative => $path) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw $this->unsupported();
            }
            if ($state === 'source') {
                $content = $this->patchV1Content($relative, $content);
            }
            $patched[$relative] = $this->patchV2Content($relative, $content);
        }

        $this->writeAllOrRestore($paths, $patched);

        return $state === 'v1' ? 'upgraded from v1' : 'patched';
    }

    /** @return array{array<string, string>, string} */
    private function validateFiles(): array
    {
        $paths = [];
        $contents = [];

        $adminIndex = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.html';
        $indexContent = is_file($adminIndex) ? file_get_contents($adminIndex) : false;
        if ($indexContent === false
            || preg_match_all('~(?:^|[./])assets/(index-[A-Za-z0-9_-]+\.js)(?:[?"\']|$)~', $indexContent, $matches) < 1
        ) {
            throw $this->unsupported();
        }
        $bundleNames = array_values(array_unique($matches[1]));
        if (count($bundleNames) !== 1) {
            throw new RuntimeException('SmartExpiry could not identify one unique admin entry bundle. No changes were applied.');
        }

        $files = array_merge(
            ['public/assets/admin/assets/' . $bundleNames[0]],
            self::LOCALE_FILES,
        );
        foreach ($files as $relative) {
            $path = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                throw $this->unsupported();
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw $this->unsupported();
            }
            $paths[$relative] = $path;
            $contents[$relative] = $content;
        }

        $fileCount = count($contents);
        $v2Count = 0;
        foreach ($contents as $relative => $content) {
            $v2Count += $this->hasV2Marker($relative, $content) ? 1 : 0;
        }
        $v1Count = count(array_filter($contents, static fn (string $content): bool => str_contains($content, self::LEGACY_MARKER)));
        if ($v2Count === $fileCount && $v1Count === 0) {
            return [$paths, 'v2'];
        }
        if ($v1Count === $fileCount && $v2Count === 0) {
            return [$paths, 'v1'];
        }
        if ($v1Count === 0 && $v2Count === 0) {
            return [$paths, 'source'];
        }
        throw new RuntimeException('SmartExpiry detected a partially patched admin build. No changes were applied.');
    }

    private function patchV1Content(string $relative, string $content): string
    {
        if ($this->isAdminBundle($relative)) {
            $functionAnchor = 'u=Nv({resolver:Mv(q8t)});return H.useEffect';
            $functionReplacement = 'u=Nv({resolver:Mv(q8t)}),h=e=>{const t=new Date,n=Number(u.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,r=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(r,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),i.setHours(23,59,59,999),u.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),o(!1)};/*smart-expiry-v1*/return H.useEffect';

            $buttonsAnchor = 'Q.jsxs("div",{className:"flex gap-2",children:[Q.jsx(Lf,{type:"button",variant:"outline",className:"flex-1",onClick:()=>{t.onChange(null),o(!1)},children:e("edit.form.expire_time_permanent")}),Q.jsx(Lf,{type:"button",variant:"outline",className:"flex-1",onClick:()=>{const e=new Date;e.setMonth(e.getMonth()+1),e.setHours(23,59,59,999),t.onChange(Math.floor(e.getTime()/1e3)),o(!1)},children:e("edit.form.expire_time_1month")}),Q.jsx(Lf,{type:"button",variant:"outline",className:"flex-1",onClick:()=>{const e=new Date;e.setMonth(e.getMonth()+3),e.setHours(23,59,59,999),t.onChange(Math.floor(e.getTime()/1e3)),o(!1)},children:e("edit.form.expire_time_3months")})]})';
            $button = static fn (int $months, string $key): string => 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>h(' . $months . '),children:e("edit.form.' . $key . '")})';
            $buttonsReplacement = 'Q.jsxs("div",{className:"flex flex-wrap gap-2",children:['
                . 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>{t.onChange(null),o(!1)},children:e("edit.form.expire_time_permanent")}),'
                . $button(1, 'expire_time_1month') . ','
                . $button(3, 'expire_time_3months') . ','
                . $button(6, 'expire_time_6months') . ','
                . $button(9, 'expire_time_9months') . ','
                . $button(12, 'expire_time_1year') . ']})';

            $content = $this->replaceExactlyOnce($content, $functionAnchor, $functionReplacement, 'renewal function');
            return $this->replaceExactlyOnce($content, $buttonsAnchor, $buttonsReplacement, 'expiry shortcut buttons');
        }

        $translations = match ($relative) {
            'public/assets/admin/locales/en-US.js' => ['Six Months', 'Nine Months', 'One Year'],
            'public/assets/admin/locales/ru-RU.js' => ['Шесть месяцев', 'Девять месяцев', 'Один год'],
            'public/assets/admin/locales/zh-CN.js' => ['六个月', '九个月', '一年'],
            default => throw $this->unsupported(),
        };
        $anchor = '"expire_time_3months": ';
        $position = strpos($content, $anchor);
        if ($position === false || strpos($content, $anchor, $position + 1) !== false) {
            throw $this->unsupported();
        }
        $lineEnd = strpos($content, "\n", $position);
        if ($lineEnd === false) {
            throw $this->unsupported();
        }
        $indent = '        ';
        $addition = "\n{$indent}\"expire_time_6months\": \"{$translations[0]}\",";
        $addition .= "\n{$indent}\"expire_time_9months\": \"{$translations[1]}\",";
        $addition .= "\n{$indent}\"expire_time_1year\": \"{$translations[2]}\",";
        $addition .= "\n{$indent}\"smart_expiry_marker\": \"" . self::MARKER . "\",";

        return substr($content, 0, $lineEnd) . $addition . substr($content, $lineEnd);
    }

    private function patchV2Content(string $relative, string $content): string
    {
        if ($this->isAdminBundle($relative)) {
            $editAnchor = 'h=e=>{const t=new Date,n=Number(u.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,r=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(r,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),i.setHours(23,59,59,999),u.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),o(!1)};/*smart-expiry-v1*/';
            $editReplacement = 'h=e=>{const t=new Date,n=Number(u.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,r=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(r,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),u.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),o(!1)};/*smart-expiry-edit-v2*/';
            $content = $this->replaceExactlyOnce($content, $editAnchor, $editReplacement, 'edit-user renewal function');

            $createFunctionAnchor = '[s,o]=H.useState([]),[a,l]=H.useState(!1);H.useEffect';
            $createFunctionReplacement = '[s,o]=H.useState([]),[a,l]=H.useState(!1),[d,u]=H.useState(!1),h=e=>{const t=new Date,n=Number(r.getValues("expired_at")),i=Number.isFinite(n)&&n>t.getTime()/1e3?new Date(1e3*n):t,s=i.getDate();i.setDate(1),i.setMonth(i.getMonth()+e),i.setDate(Math.min(s,new Date(i.getFullYear(),i.getMonth()+1,0).getDate())),r.setValue("expired_at",Math.floor(i.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0}),u(!1)};/*smart-expiry-create-v2*/H.useEffect';
            $content = $this->replaceExactlyOnce($content, $createFunctionAnchor, $createFunctionReplacement, 'create-user renewal function');

            $createPickerAnchor = 'Q.jsxs(P$t,{children:[Q.jsx(j$t,{asChild:!0,children:Q.jsx(Yy,{children:Q.jsxs(Lf,{variant:"outline",className:Im("h-9 w-full px-3 text-left font-mono text-xs font-normal",!r.watch("expired_at")&&"text-muted-foreground"),children:[r.watch("expired_at")?SS(r.watch("expired_at")):Q.jsx("span",{children:t("generate.form.expire_time_placeholder")}),Q.jsx(vat,{className:"ml-auto h-3.5 w-3.5 opacity-50"})]})})}),Q.jsxs(B$t,{className:"flex w-auto flex-col space-y-2 p-2",children:[Q.jsx(R$t,{asChild:!0,children:Q.jsx(Lf,{variant:"outline",className:"h-8 w-full font-mono text-xs",onClick:()=>{r.setValue("expired_at",null)},children:t("generate.form.permanent")})}),Q.jsx("div",{className:"rounded-md border",children:Q.jsx(o$t,{mode:"single",selected:r.watch("expired_at")?new Date(1e3*r.watch("expired_at")):void 0,onSelect:e=>{e&&r.setValue("expired_at",e?.getTime()/1e3)}})})]})]})';
            $button = static fn (int $months, string $key): string => 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>h(' . $months . '),children:t("generate.form.' . $key . '")})';
            $createPickerReplacement = 'Q.jsxs(P$t,{open:d,onOpenChange:u,children:[Q.jsx(j$t,{asChild:!0,children:Q.jsx(Yy,{children:Q.jsxs(Lf,{type:"button",variant:"outline",className:Im("h-9 w-full px-3 text-left font-mono text-xs font-normal",!r.watch("expired_at")&&"text-muted-foreground"),onClick:()=>u(!0),children:[r.watch("expired_at")?SS(r.watch("expired_at")):Q.jsx("span",{children:t("generate.form.expire_time_placeholder")}),Q.jsx(vat,{className:"ml-auto h-3.5 w-3.5 opacity-50"})]})})}),Q.jsx(B$t,{className:"w-auto p-0",align:"start",side:"top",sideOffset:4,onInteractOutside:e=>{e.preventDefault()},onEscapeKeyDown:e=>{e.preventDefault()},children:Q.jsxs("div",{className:"flex flex-col space-y-3 p-3",children:[Q.jsxs("div",{className:"flex flex-wrap gap-2",children:['
                . 'Q.jsx(Lf,{type:"button",variant:"outline",className:"min-w-[5rem] flex-1",onClick:()=>{r.setValue("expired_at",null,{shouldDirty:!0,shouldValidate:!0}),u(!1)},children:t("generate.form.permanent")}),'
                . $button(1, 'expire_time_1month') . ','
                . $button(3, 'expire_time_3months') . ','
                . $button(6, 'expire_time_6months') . ','
                . $button(9, 'expire_time_9months') . ','
                . $button(12, 'expire_time_1year')
                . ']}),Q.jsx("div",{className:"rounded-md border",children:Q.jsx(o$t,{mode:"single",selected:r.watch("expired_at")?new Date(1e3*r.watch("expired_at")):void 0,onSelect:e=>{if(e){const t=new Date(r.getValues("expired_at")?1e3*r.getValues("expired_at"):Date.now());e.setHours(t.getHours(),t.getMinutes(),t.getSeconds()),r.setValue("expired_at",Math.floor(e.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0})}},disabled:e=>e<new Date,initialFocus:!0,className:"rounded-md border-none"})}),Q.jsxs("div",{className:"space-y-1.5",children:[Q.jsxs("div",{className:"flex items-center justify-between",children:[Q.jsx("div",{className:"text-sm font-medium text-muted-foreground",children:t("generate.form.expire_time_specific")}),Q.jsx(Lf,{type:"button",variant:"ghost",size:"sm",onClick:()=>{const e=new Date;e.setHours(23,59,59,999),r.setValue("expired_at",Math.floor(e.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0})},className:"h-6 px-2 text-xs",children:t("generate.form.expire_time_today")})]}),Q.jsxs("div",{className:"flex gap-2",children:[Q.jsx(u8e,{type:"datetime-local",step:"1",value:SS(r.watch("expired_at"),"yyyy-MM-dd\'T\'HH:mm:ss"),onChange:e=>{const t=new Date(e.target.value);isNaN(t.getTime())||r.setValue("expired_at",Math.floor(t.getTime()/1e3),{shouldDirty:!0,shouldValidate:!0})},className:"flex-1"}),Q.jsx(Lf,{type:"button",variant:"outline",onClick:()=>u(!1),children:t("generate.form.expire_time_confirm")})]})]})]})})]})';

            return $this->replaceExactlyOnce($content, $createPickerAnchor, $createPickerReplacement, 'create-user expiry picker');
        }

        $translations = match ($relative) {
            'public/assets/admin/locales/en-US.js' => ['Permanent', 'One Month', 'Three Months', 'Six Months', 'Nine Months', 'One Year', 'Specific Time', 'Set to end of today', 'Confirm'],
            'public/assets/admin/locales/ru-RU.js' => ['Навсегда', 'Один месяц', 'Три месяца', 'Шесть месяцев', 'Девять месяцев', 'Один год', 'Конкретное время', 'До конца сегодня', 'Подтвердить'],
            'public/assets/admin/locales/zh-CN.js' => ['永久', '一个月', '三个月', '六个月', '九个月', '一年', '具体时间', '设为当天结束', '确定'],
            default => throw $this->unsupported(),
        };
        $permanentAnchor = '        "permanent": ';
        $position = false;
        $offset = 0;
        while (($candidate = strpos($content, $permanentAnchor, $offset)) !== false) {
            $candidateEnd = strpos($content, "\n", $candidate);
            if ($candidateEnd !== false && str_starts_with(substr($content, $candidateEnd), "\n        \"subscription\":")) {
                if ($position !== false) {
                    throw $this->unsupported();
                }
                $position = $candidate;
            }
            $offset = $candidate + strlen($permanentAnchor);
        }
        if ($position === false) {
            throw $this->unsupported();
        }
        $lineEnd = strpos($content, "\n", $position);
        if ($lineEnd === false) {
            throw $this->unsupported();
        }
        $replacement = $permanentAnchor . '"' . $translations[0] . '",';
        $replacement .= "\n        \"expire_time_1month\": \"{$translations[1]}\",";
        $replacement .= "\n        \"expire_time_3months\": \"{$translations[2]}\",";
        $replacement .= "\n        \"expire_time_6months\": \"{$translations[3]}\",";
        $replacement .= "\n        \"expire_time_9months\": \"{$translations[4]}\",";
        $replacement .= "\n        \"expire_time_1year\": \"{$translations[5]}\",";
        $replacement .= "\n        \"expire_time_specific\": \"{$translations[6]}\",";
        $replacement .= "\n        \"expire_time_today\": \"{$translations[7]}\",";
        $replacement .= "\n        \"expire_time_confirm\": \"{$translations[8]}\",";
        $replacement .= "\n        \"smart_expiry_marker_v2\": \"" . self::MARKER . "\",";
        $content = substr($content, 0, $position) . $replacement . substr($content, $lineEnd);

        return str_replace('"smart_expiry_marker": "' . self::LEGACY_MARKER . '"', '"smart_expiry_marker": "' . self::MARKER . '"', $content);
    }

    /**
     * @param array<string, string> $paths
     * @param array<string, string> $patched
     */
    private function writeAllOrRestore(array $paths, array $patched): void
    {
        $originals = [];
        $written = [];
        try {
            foreach ($patched as $relative => $content) {
                $original = file_get_contents($paths[$relative]);
                if ($original === false) {
                    throw new RuntimeException("SmartExpiry could not read {$relative} before writing.");
                }
                $originals[$relative] = $original;
                $written[] = $relative;
                if (file_put_contents($paths[$relative], $content, LOCK_EX) === false) {
                    throw new RuntimeException("SmartExpiry could not write {$relative}.");
                }
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($written) as $relative) {
                file_put_contents($paths[$relative], $originals[$relative], LOCK_EX);
            }
            throw $exception;
        }
    }

    private function replaceExactlyOnce(string $content, string $search, string $replacement, string $name): string
    {
        if (substr_count($content, $search) !== 1) {
            throw new RuntimeException("SmartExpiry could not find the unique {$name} anchor. No changes were applied.");
        }

        return str_replace($search, $replacement, $content);
    }

    private function isAdminBundle(string $relative): bool
    {
        return str_starts_with($relative, 'public/assets/admin/assets/index-')
            && str_ends_with($relative, '.js');
    }

    private function hasV2Marker(string $relative, string $content): bool
    {
        if ($this->isAdminBundle($relative)) {
            return str_contains($content, 'smart-expiry-edit-v2')
                && str_contains($content, 'smart-expiry-create-v2');
        }

        return str_contains($content, self::MARKER);
    }

    private function unsupported(): RuntimeException
    {
        return new RuntimeException("Unsupported Xboard admin build.\nSmartExpiry patch was not applied.");
    }
}
