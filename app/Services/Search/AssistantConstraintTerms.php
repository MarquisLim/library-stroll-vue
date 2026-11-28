<?php

namespace App\Services\Search;

/** Small bilingual vocabulary for relating grounded query spans to the predominantly English Vision text. */
final class AssistantConstraintTerms
{
    private const SUBJECTS = [
        'bird birds птица птицы птиц птицу', 'sparrow sparrows воробей воробья воробьев воробьи',
        'parrot parrots попугай попугая попугаев попугаи', 'cockatiel cockatiels корелла кореллы кореллу',
        'hawk hawks falcon falcons сокол сокола ястреб ястреба', 'pelican pelicans пеликан пеликана пеликанов пеликаны',
        'duck ducks утка уток утки утку селезень', 'man male мужчины мужчина мужчину мужчины мужик men',
        'woman women женщина женщину женщины женщин female', 'person people human humans люди людей человек человека',
        'child children baby infant toddler boy ребенок ребенка ребенок малыш малыша дети младенец младенца мальчик мальчика',
        'beard beards борода бороды бородой бороду', 'hat hats cap caps шапка шляпа кепка кепку кепке кепкой шляпе',
        'dragon dragons дракон дракона драконы драконов', 'tiger tigers тигр тигра тигры тигров',
        'castle castles замок замка замком', 'bridge bridges мост моста мостом', 'water вода воды водой',
        'stream creek brook ручей ручья ручьем', 'river rivers река рекой реки реку',
        'plate plates тарелка тарелки тарелку тарелок', 'bowl bowls миска миску миски чаша чашу чаши',
        'cup cups чашка чашки чашку кружка кружки', 'vase vases ваза вазу вазе вазы',
        'flower flowers цветок цветка цветы цветов цветами', 'costume costumes outfit outfits наряд наряда костюм костюмы костюма костюме',
        'caftan kaftan coat кафтан кафтана кафтане',
        'dress dresses gown gowns платье платья платьем', 'jacket jackets пиджак пиджаке куртка куртке',
        'boat boats лодка лодки лодок лодку', 'ship ships корабль корабли корабля кораблей судно судна суда',
        'mountain mountains гора горы гор горная горный', 'landscape landscapes пейзаж пейзажи пейзажа',
        'tree trees дерево деревья деревьев', 'basket baskets корзина корзине корзину',
        'oar paddle весло весла веслом', 'astronaut astronauts астронавт космонавт астронавта космонавта',
        'pacifier dummy соска соской соску', 'glasses spectacles очки очках очков',
        'zebra zebras зебра зебру зебры', 'bicycle bicycles велосипед велосипеды велосипеда',
    ];

    private const COLORS = [
        'red красный красная красное красные красном красного красной красную красными', 'blue синий синяя синее синие синем синего синей синюю',
        'yellow желтый желтая желтое желтые желтом желтого желтой желтую', 'white белый белая белое белые белом белого белой белую',
        'black черный черная черное черные черном черного черной черную', 'green зеленый зеленая зеленое зеленые зеленом зеленого зеленой зеленую',
        'brown коричневый коричневая коричневое коричневые коричневом коричневого', 'orange оранжевый оранжевая оранжевое оранжевом',
        'pink розовый розовая розовое розовом', 'purple violet фиолетовый фиолетовая фиолетовом',
        'gray grey серый серая серое серые сером', 'turquoise бирюзовый бирюзовая бирюзовом', 'burgundy бордовый бордовая бордовом',
    ];

    private const ATTRIBUTES = [
        'bearded|бородатый|бородатого|с бородой|with a beard|with beard|a beard|beard',
        'embroidered|вышитый|вышитая|вышитое|с вышивкой|with embroidery|embroidery',
        'striped|полосатый|полосатая|полосатой|в полоску', 'crested|with a crest|raised crest|с хохолком|хохолок',
        'stone|каменный|каменная|каменного|из камня|made of stone', 'wooden|деревянный|деревянная|made of wood',
    ];

    private const FORMATS = [
        'photograph photo photography фотография фото снимок', 'painting paintings картина картины живопись',
        'illustration illustrations иллюстрация иллюстрации', 'drawing drawings рисунок рисунки рисунке sketch набросок',
        'logo logos логотип логотипы emblem знак', 'print prints гравюра гравюры', 'portrait портрет портреты',
    ];

    private const STYLES = [
        'watercolor|watercolour|акварель|акварельный|акварельная|акварельные',
        'black and white|monochrome|grayscale|greyscale|черно белый|черно белая|черно белое|черно белые|черно белом|монохром',
        'abstract|абстрактный|абстрактная|абстрактное|абстракция',
        'minimalist|minimalism|минимализм|минималистичный',
        'ink|тушь|тушью', 'impressionism|impressionist|импрессионизм',
    ];

    private const SCENES = [
        'forest woods woodland лес лесу лесом', 'sea ocean море морем океан океаном',
        'mountains mountain гора горах горы гор', 'harbor harbour port гавань порту порт',
        'night nighttime nocturnal ночь ночью ночной ночная', 'city urban город городе городской',
        'space космос космосе космический', 'indoors interior помещение помещении интерьер',
    ];

    private const RELATIONS = [
        'above|over|над|выше|spanning|crossing|через',
        'below|under|под|ниже',
        'beside|next to|near|рядом|рядом с|возле|у',
        'left of|to the left of|слева от', 'right of|to the right of|справа от',
        'in front of|перед', 'behind|позади|за',
        'on|на', 'wearing|wears|в|в одежде',
    ];

    public static function normalize(string $text): string
    {
        $text = str_replace('ё', 'е', mb_strtolower($text, 'UTF-8'));

        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $text) ?? '');
    }

    /** @return non-empty-list<string>|list<never> */
    public static function forms(string $type, string $text): array
    {
        $text = self::normalize($text);
        $text = preg_replace('/^(?:the|a|an) /', '', $text);
        $table = match ($type) {
            'object', 'presence' => self::SUBJECTS,
            'color', 'background_color' => self::COLORS,
            'attribute' => self::ATTRIBUTES,
            'media' => self::FORMATS,
            'style' => self::STYLES,
            'scene' => self::SCENES,
            'relation' => self::RELATIONS,
            default => [],
        };
        if ($type === 'background_color') {
            $text = preg_replace('/^(?:on|against|на) /u', '', $text);
            $text = preg_replace('/^(?:a|the|mostly|predominantly|преимущественно) /u', '', $text);
            $text = preg_replace('/ (?:background|backdrop|фон|фоне)$/u', '', $text);
        }
        if ($type === 'scene') {
            $text = preg_replace('/^(?:in|at|within|в|на) /u', '', $text);
        }
        foreach ($table as $row) {
            $forms = explode(str_contains($row, '|') ? '|' : ' ', $row);
            if (in_array($text, $forms, true)) {
                return array_values(array_unique($forms));
            }
        }

        // Unknown subject names can still match a short literal label. Unknown binding cues abstain.
        return in_array($type, ['object', 'presence', 'scene', 'style'], true)
            && preg_match('/\A[\pL\pN]+(?: [\pL\pN]+){0,2}\z/u', $text) ? [$text] : [];
    }

    public static function pattern(array $forms): string
    {
        return '(?:'.implode('|', array_map(fn ($form) => preg_quote($form, '~'), $forms)).')';
    }

    public static function colorForms(): string
    {
        return self::pattern(explode(' ', implode(' ', self::COLORS)));
    }

    public static function oppositeRelation(array $forms): array
    {
        $opposite = ['above' => 'below', 'below' => 'above', 'left of' => 'right of', 'right of' => 'left of',
            'in front of' => 'behind', 'behind' => 'in front of'][$forms[0]] ?? null;

        return $opposite === null ? [] : self::forms('relation', $opposite);
    }
}
