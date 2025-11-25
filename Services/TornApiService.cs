using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;
using TornPropertyManager.Models;

public class TornApiService
{
    private static readonly HttpClient Http = new HttpClient();

    public async Task<bool> ValidateKeyAsync(string apiKey)
    {
        if (string.IsNullOrWhiteSpace(apiKey))
            return false;

        // Use a cheap selection; change to "properties" if you prefer
        var url = $"https://api.torn.com/user/?selections=profile&key={apiKey.Trim()}";

        try
        {
            using var response = await Http.GetAsync(url);
            if (!response.IsSuccessStatusCode)
                return false;

            var json = await response.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(json);

            // Torn wraps errors in an "error" object
            if (doc.RootElement.TryGetProperty("error", out _))
                return false;

            return true;
        }
        catch
        {
            return false;
        }
    }

    public async Task<IReadOnlyList<TornPropertySummary>> GetPropertiesAsync(string apiKey)
{
    var results = new List<TornPropertySummary>();

    if (string.IsNullOrWhiteSpace(apiKey))
        return results;

    var url = $"https://api.torn.com/user/?selections=properties&key={apiKey.Trim()}";

    try
    {
        using var response = await Http.GetAsync(url);
        if (!response.IsSuccessStatusCode)
            return results;

        var json = await response.Content.ReadAsStringAsync();
        using var doc = JsonDocument.Parse(json);
        var root = doc.RootElement;

        if (root.TryGetProperty("error", out _))
            return results;

        if (!root.TryGetProperty("properties", out var propsElement) ||
            propsElement.ValueKind != JsonValueKind.Object)
            return results;

        foreach (var propKv in propsElement.EnumerateObject())
        {
            var propObj = propKv.Value;
            var summary = new TornPropertySummary();

            // Property ID is the JSON object key
            if (int.TryParse(propKv.Name, out int propId))
                summary.PropertyId = propId;

            summary.PropertyName = propObj.GetProperty("property").GetString() ?? "";
            summary.Status = propObj.GetProperty("status").GetString() ?? "";
            summary.Happy = propObj.GetProperty("happy").GetInt32();
            summary.Upkeep = propObj.GetProperty("upkeep").GetInt32();
            summary.StaffCost = propObj.GetProperty("staff_cost").GetInt32();
            summary.MarketPrice = propObj.GetProperty("marketprice").GetInt32();

            // Rented block can be null OR an object
            if (propObj.TryGetProperty("rented", out var rentedEl) &&
                rentedEl.ValueKind == JsonValueKind.Object)
            {
                summary.IsRentedOut = true;

                if (rentedEl.TryGetProperty("user_id", out var uid))
                    summary.RentedToUserId = uid.GetInt32();

                if (rentedEl.TryGetProperty("days_left", out var dl))
                    summary.DaysLeft = dl.GetInt32();

                if (rentedEl.TryGetProperty("total_cost", out var tc))
                    summary.RentTotalCost = tc.GetInt32();

                if (rentedEl.TryGetProperty("cost_per_day", out var cp))
                    summary.RentCostPerDay = cp.GetInt32();
            }
            else
            {
                summary.IsRentedOut = false;
            }

            results.Add(summary);
        }
    }
    catch
    {
        // ignore for now
    }

    return results;
}

}
