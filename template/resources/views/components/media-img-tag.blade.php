{{-- `<x-media-img>` が出す <img> 本体。<picture> の中でも外でも同じものを出すために切り出した
     （2 か所に書くと、片方だけ属性が抜ける）。単体で使うことは想定していない。 --}}
@props(['media', 'loading' => 'lazy', 'imgClass' => '', 'alt' => null])

<img
    src="{{ $media->url() }}"
    alt="{{ $alt ?? $media->alt ?? '' }}"
    width="{{ $media->width }}"
    height="{{ $media->height }}"
    loading="{{ $loading }}"
    decoding="async"
    @if($imgClass) class="{{ $imgClass }}" @endif
>
