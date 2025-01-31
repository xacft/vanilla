/**
 * @copyright 2009-2022 Vanilla Forums Inc.
 * @license gpl-2.0-only
 */

import { extractDataByKeyLookup } from "@dashboard/layout/editor/utils";
import { EMPTY_SCHEMA, JsonSchema } from "@vanilla/json-schema-forms";

describe("WidgetSettings", () => {
    //4 level nested complex object
    const mockWidgetSchema: JsonSchema = {
        type: "object",
        properties: {
            level1: {
                type: "string",
            },
            level1_withDefault_value: {
                type: "string",
                default: "foo",
            },
            level1_withNested_value: {
                type: "object",
                properties: {
                    level2_withDefault_value: {
                        type: "integer",
                        default: 10,
                    },
                    level2: {
                        type: "string",
                    },
                },
                required: [],
            },
            level1_withDeepNested_value: {
                type: "object",
                properties: {
                    level2_withNested_value: {
                        type: "object",
                        properties: {
                            level3_withDefault_value: {
                                type: "boolean",
                                default: true,
                            },
                            level3: {
                                type: "boolean",
                            },
                            level3_default_isUndefined: {
                                type: "boolean",
                                default: undefined,
                            },
                            level3_withNested_value: {
                                type: "object",
                                properties: {
                                    level4_deeplyNested_default_value: {
                                        type: "boolean",
                                        default: true,
                                    },
                                    level4: {
                                        type: "boolean",
                                    },
                                    level4_default_isNull: {
                                        type: "boolean",
                                        default: null,
                                    },
                                },
                                required: [],
                            },
                        },
                        required: [],
                    },
                    level2: {
                        type: "string",
                    },
                },
                required: [],
            },
        },
        required: [],
    };

    it("extract default props from widget schema", () => {
        //all values with key "default"
        expect(extractDataByKeyLookup(mockWidgetSchema, "default")).toStrictEqual({
            level1_withDefault_value: "foo",
            level1_withNested_value: {
                level2_withDefault_value: 10,
            },
            level1_withDeepNested_value: {
                level2_withNested_value: {
                    level3_withDefault_value: true,
                    level3_withNested_value: {
                        level4_deeplyNested_default_value: true,
                        level4_default_isNull: null,
                    },
                },
            },
        });

        //empty object, we don't have that key
        expect(extractDataByKeyLookup(mockWidgetSchema, "someKey")).toStrictEqual({});

        //empty object, empty object sent to lookup
        expect(extractDataByKeyLookup(EMPTY_SCHEMA, "someKey")).toStrictEqual({});
    });
});
