<?php

namespace App\Data\Shipping;

final readonly class DestinationClassification
{
    public ?bool $is_road_connected;

    /**
     * @param  array<string, string>  $source
     */
    public function __construct(
        public ?string $scope,
        public ?bool $is_island,
        public ?bool $road_connected_to_mainland,
        public string $policy,
        public string $matched_by,
        public string $dataset_version,
        public array $source,
        public ?string $postal_code = null,
        public ?string $city = null,
        public ?string $island = null,
    ) {
        $this->is_road_connected = $road_connected_to_mainland;
    }

    /**
     * @return array{
     *     scope: string|null,
     *     is_island: bool|null,
     *     road_connected_to_mainland: bool|null,
     *     is_road_connected: bool|null,
     *     policy: string,
     *     postal_code: string|null,
     *     city: string|null,
     *     island: string|null,
     *     matched_by: string,
     *     dataset_version: string,
     *     source: array<string, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'is_island' => $this->is_island,
            'road_connected_to_mainland' => $this->road_connected_to_mainland,
            'is_road_connected' => $this->is_road_connected,
            'policy' => $this->policy,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'island' => $this->island,
            'matched_by' => $this->matched_by,
            'dataset_version' => $this->dataset_version,
            'source' => $this->source,
        ];
    }
}
