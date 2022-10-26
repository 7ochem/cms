<?php

namespace helpers;

use craft\errors\ImageTransformException;
use craft\helpers\ArrayHelper;
use craft\helpers\ImageTransforms;
use craft\models\ImageTransform;

class ImageTransformsHelperTest extends \Codeception\Test\Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private $fullTransform = [
        'id' => 123,
        'name' => 'Test Transform',
        'transformer' => ImageTransform::DEFAULT_TRANSFORMER,
        'handle' => 'testTransform',
        'width' => 100,
        'height' => 200,
        'format' => 'jpg',
        'mode' => 'fit',
        'position' => 'center-center',
        'fill' => '#ff0000',
        'quality' => 95,
        'interlace' => 'line',
    ];

    public function testCreateTransformFromStringInvalid()
    {
        $this->tester->expectThrowable(ImageTransformException::class, function() {
            ImageTransforms::createTransformFromString('some_invalid_string');
        });
    }

    /**
     * @dataProvider createTransformsFromStringProvider
     * @return void
     * @throws ImageTransformException
     */
    public function testCreateTransformFromString(array $expected, string $string)
    {
        $transform = ImageTransforms::createTransformFromString($string);

        foreach ($expected as $property => $value) {
            $this->assertSame($transform->{$property}, $value);
        }
    }

    protected function createTransformsFromStringProvider()
    {
        return [
            'happy path' => [
                [
                    'width' => 1280,
                    'height' => 600,
                    'mode' => 'crop',
                    'position' => 'center-center',
                ],
                '_1280x600_crop_center-center',
            ],
            'with quality' => [
                [
                    'quality' => 95,
                ],
                '_1280x600_crop_center-center_95',
            ],
            'with interlace' => [
                [
                    'interlace' => 'line',
                ],
                '_1280x600_crop_center-center_95_line',
            ],
            'with fill' => [
                [
                    'fill' => '#ff0000',
                ],
                '_1280x600_crop_center-center_95_line_ff0000',
            ],
            'invalid fill' => [
                [
                    'fill' => null,
                ],
                '_1280x600_crop_center-center_95_line_invalidFill',
            ],
        ];
    }

    /**
     * @dataProvider normalizeTransformProvider
     */
    public function testNormalizeTransform($expected, $input)
    {
        $transform = ImageTransforms::normalizeTransform($input);

        if ($expected === null) {
            $this->assertSame($expected, $transform);
        } else {
            $this->assertInstanceOf(ImageTransform::class, $transform);

            foreach ($expected as $property => $value) {
                $this->assertSame($transform->$property, $value);
            }
        }
    }

    protected function normalizeTransformProvider(): array
    {
        return [
            'false' => [null, false],
            'empty string' => [null, ''],
            'true' => [null, true],
            'object' => [
                $this->fullTransform,
                (object)$this->fullTransform,
            ],
            'array' => [
                $this->fullTransform,
                $this->fullTransform,
            ],
            'non-numeric width' => [
                ArrayHelper::merge($this->fullTransform, ['width' => null]),
                ArrayHelper::merge($this->fullTransform, ['width' => 'not a number']),
            ],
            'non-numeric height' => [
                ArrayHelper::merge($this->fullTransform, ['height' => null]),
                ArrayHelper::merge($this->fullTransform, ['height' => 'not a number']),
            ],
            'invalid fill' => [
                [
                    'fill' => null,
                ],
                ArrayHelper::merge($this->fullTransform, ['fill' => 'invalidFill']),
            ],
            'extended transform' => [
                [
                    'id' => null,
                    'name' => null,
                    'width' => $this->fullTransform['width'],
                    'height' => $this->fullTransform['height'],
                ],
                ArrayHelper::merge($this->fullTransform, [
                    'transform' => [
                        'id' => '200',
                        'name' => 'Base Transform',
                        'width' => '300',
                        'height' => '400',
                    ],
                ]),
            ],
            'valid string' => [
                [
                    'width' => 1280,
                    'height' => 600,
                    'mode' => 'crop',
                    'position' => 'center-center',
                ],
                '_1280x600_crop_center-center',
            ],
        ];
    }

    /**
     * @dataProvider getTransformStringProvider
     * @param $expected
     * @param $input
     * @return void
     */
    public function testGetTransformString($expected, $input)
    {
        $transform = new ImageTransform($input);
        $this->assertSame($expected, ImageTransforms::getTransformString($transform));
    }

    protected function getTransformStringProvider(): array
    {
        return [
            'basic transform' => [
                '_1200x900_crop_center-center_none',
                [
                    'width' => 1200,
                    'height' => 900,
                ],
            ],
            'no width' => [
                '_AUTOx900_crop_center-center_none',
                [
                    'width' => null,
                    'height' => 900,
                ],
            ],
            'no height' => [
                '_1200xAUTO_crop_center-center_none',
                [
                    'width' => 1200,
                    'height' => null,
                ],
            ],
            'with handle' => [
                '_' . $this->fullTransform['handle'],
                $this->fullTransform,
            ],
            'full transform' => [
                '_100x200_fit_center-center_95_line_ff0000',
                ArrayHelper::merge($this->fullTransform, ['handle' => null]),
            ],
        ];
    }
}
