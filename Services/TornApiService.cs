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
            {
                // Key invalid / wrong access / etc.
                return results;
            }

            if (!root.TryGetProperty("properties", out var propsElement) ||
                propsElement.ValueKind != JsonValueKind.Object)
            {
                // No properties selection or nothing there
                return results;
            }

            foreach (var prop in propsElement.EnumerateObject())
            {
                var propObj = prop.Value;
                var summary = new TornPropertySummary();

                // ID is usually the property id or index; we treat it as id
                if (int.TryParse(prop.Name, out var idFromKey))
                {
                    summary.PropertyId = idFromKey;
                }

                if (propObj.TryGetProperty("property_id", out var pidEl) &&
                    pidEl.ValueKind == JsonValueKind.Number)
                {
                    summary.PropertyId = pidEl.GetInt32();
                }

                if (propObj.TryGetProperty("name", out var nameEl) &&
                    nameEl.ValueKind == JsonValueKind.String)
                {
                    summary.Name = nameEl.GetString() ?? "";
                }

                if (propObj.TryGetProperty("type", out var typeEl) &&
                    typeEl.ValueKind == JsonValueKind.String)
                {
                    summary.Type = typeEl.GetString() ?? "";
                }

                if (propObj.TryGetProperty("rented", out var rentedEl) &&
                    rentedEl.ValueKind == JsonValueKind.Number)
                {
                    summary.IsRented = rentedEl.GetInt32() == 1;
                }

                if (propObj.TryGetProperty("status", out var statusEl) &&
                    statusEl.ValueKind == JsonValueKind.String)
                {
                    summary.Status = statusEl.GetString() ?? "";
                }

                results.Add(summary);
            }
        }
        catch
        {
            // Swallow for now; you can add logging later
        }

        return results;
    }
}
